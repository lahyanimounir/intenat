<?php

namespace Tests\Feature;

use App\Domain\Identity\InscriptionStagiaire;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\StatutInscription;
use App\Models\Stagiaire;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deux sessions de base de données réelles créent le même compte, ou déclarent
 * le même CEF, en même temps.
 *
 * Comme AffectationConcurrenteTest, ce test n'est pas enveloppé dans une
 * transaction : les deux connexions doivent voir les mêmes lignes validées.
 * Il nettoie donc lui-même les tables qu'il a remplies.
 */
class InscriptionConcurrenteTest extends TestCase
{
    private const CONSERVEES = ['migrations', 'roles', 'permissions', 'role_has_permissions', 'parametre'];

    private const CEF = '2026900000041';

    private const EMAIL = 'double.inscription@exemple.test';

    private const MOT_DE_PASSE = 'Mon-Internat-2026';

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => RolesEtPermissionsSeeder::class]);
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_concurrent')->disconnect();
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (DB::select('SHOW TABLES') as $ligne) {
            $table = array_values((array) $ligne)[0];

            if (! in_array($table, self::CONSERVEES, true)) {
                DB::table($table)->truncate();
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        parent::tearDown();
    }

    /**
     * Ligne d'un compte tout juste créé, telle que l'écrit une autre session.
     *
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private function ligne(array $valeurs = []): array
    {
        return $valeurs + [
            'email' => self::EMAIL, 'mot_de_passe_hash' => 'empreinte-de-la-session-a', 'etat_compte' => EtatCompte::Actif->value,
            'statut_inscription' => StatutInscription::NonDeclaree->value, 'source_identite' => 'declaration',
            'inscrit_le' => now(), 'compte_active_le' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function refuse(string $code, callable $action): RegleMetierException
    {
        try {
            $action();
        } catch (RegleMetierException $regle) {
            $this->assertSame($code, $regle->codeRegle, $regle->getMessage());

            return $regle;
        }

        $this->fail("La règle « {$code} » n'a pas été appliquée.");
    }

    public function test_deux_creations_simultanees_de_la_meme_adresse_ne_creent_qu_un_compte(): void
    {
        $inscription = app(InscriptionStagiaire::class);

        // Session A : le compte est écrit, la transaction n'est pas encore validée (deux onglets, deux appareils).
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('stagiaire')->insert($this->ligne());

        // Session B : crée le même compte. Elle attend le verrou de l'index unique, puis renonce proprement.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->assertSame(409, $this->refuse('operation_en_cours', fn () => $inscription->creerCompte(self::EMAIL, self::MOT_DE_PASSE))->statutHttp);

        // A valide. B réessaie : l'adresse est prise, et le refus ne décrit pas le compte existant.
        $a->commit();
        $refus = $this->refuse('adresse_indisponible', fn () => $inscription->creerCompte(mb_strtoupper(self::EMAIL), self::MOT_DE_PASSE));
        $this->assertStringNotContainsString('existe', $refus->getMessage());

        $comptes = Stagiaire::where('email', self::EMAIL)->get();
        $this->assertCount(1, $comptes);
        $this->assertSame('empreinte-de-la-session-a', $comptes[0]->mot_de_passe_hash);
    }

    public function test_deux_comptes_ne_peuvent_pas_declarer_le_meme_cef_en_meme_temps(): void
    {
        $inscription = app(InscriptionStagiaire::class);
        $premier = $inscription->creerCompte('premier@exemple.test', self::MOT_DE_PASSE);
        $second = $inscription->creerCompte('second@exemple.test', self::MOT_DE_PASSE);
        $identite = ['cef' => self::CEF, 'nom' => 'Alami', 'prenom' => 'Sara', 'genre' => Genre::Fille->value, 'tel' => '0600000000', 'ville' => 'Settat', 'adresse' => '12 rue des Orangers'];

        // Session A : le premier compte écrit ce CEF, sans avoir encore validé.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('stagiaire')->where('id', $premier->id)->update(['cef' => self::CEF]);

        // Session B : le second compte déclare le même CEF. Il attend, puis renonce.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $this->refuse('operation_en_cours', fn () => $inscription->declarerIdentite($second, $identite));

        // A valide. B réessaie : le CEF appartient au premier compte, il n'est ni partagé ni déplacé.
        $a->commit();
        $this->refuse('cef_deja_utilise', fn () => $inscription->declarerIdentite($second->fresh(), $identite));

        $this->assertSame([$premier->id], Stagiaire::where('cef', self::CEF)->pluck('id')->all());
        $this->assertNull($second->fresh()->cef);
        $this->assertNull($second->fresh()->nom);
    }

    public function test_si_les_verrous_etaient_contournes_les_index_uniques_arretent_le_doublon(): void
    {
        // Deux sessions insèrent le même compte sans aucun verrou applicatif : la base sérialise sur l'index unique.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('stagiaire')->insert($this->ligne(['cef' => self::CEF]));

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $refuse = false;

        try {
            DB::table('stagiaire')->insert($this->ligne(['cef' => self::CEF]));
        } catch (QueryException) {
            $refuse = true; // attente du verrou d'index dépassée : l'insertion concurrente n'aboutit pas
        }

        $a->commit();

        if (! $refuse) {
            $this->fail('La seconde insertion aurait dû être bloquée par l\'index unique.');
        }

        // Même CEF, autre adresse ; même adresse, autre CEF : refusés tous les deux.
        foreach ([['cef' => self::CEF, 'email' => 'autre@exemple.test'], ['cef' => '2026900000042']] as $variante) {
            try {
                DB::table('stagiaire')->insert($this->ligne($variante));
                $this->fail('Doublon accepté.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(1, Stagiaire::count());
            }
        }
    }
}
