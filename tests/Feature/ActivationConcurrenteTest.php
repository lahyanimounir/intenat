<?php

namespace Tests\Feature;

use App\Domain\Identity\ActivationCompte;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\MethodeVerification;
use App\Enums\ResultatVerification;
use App\Models\Etablissement;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deux sessions de base de données réelles activent le même stagiaire en même temps.
 *
 * Comme AffectationConcurrenteTest, ce test n'est pas enveloppé dans une
 * transaction : les deux connexions doivent voir les mêmes lignes validées.
 * Il nettoie donc lui-même les tables qu'il a remplies.
 */
class ActivationConcurrenteTest extends TestCase
{
    private const CONSERVEES = ['migrations', 'roles', 'permissions', 'role_has_permissions', 'parametre'];

    private const CEF = '2026900000041';

    private const EMAIL = 'double.activation@exemple.test';

    private Etablissement $etablissement;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => RolesEtPermissionsSeeder::class]);
            RefreshDatabaseState::$migrated = true;
        }

        $this->etablissement = Etablissement::factory()->create();
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

    /** Une vérification réussie encore utilisable, et son jeton en clair. */
    private function verification(): string
    {
        $jeton = Str::random(64);

        VerificationIdentite::create([
            'fournisseur' => 'mock', 'methode' => MethodeVerification::Identifiants, 'simulee' => true,
            'resultat' => ResultatVerification::Verifiee, 'cef' => self::CEF, 'email' => self::EMAIL,
            'profil' => ['nom' => 'Alami', 'prenom' => 'Sara', 'cni' => null, 'tel' => null, 'ville' => null, 'origines' => []],
            'etablissement_id' => $this->etablissement->id, 'nom_efp' => $this->etablissement->nom_efp,
            'nom_filiere' => 'Développement digital', 'statut_candidature' => 'Inscrit Payé',
            'jeton_hash' => VerificationIdentite::empreinte($jeton), 'expire_le' => now()->addMinutes(30),
        ]);

        return $jeton;
    }

    /** @return array<string, mixed> */
    private function ligneStagiaire(): array
    {
        return [
            'etablissement_id' => $this->etablissement->id, 'cef' => self::CEF, 'nom' => 'Alami', 'prenom' => 'Sara', 'email' => self::EMAIL,
            'mot_de_passe_hash' => 'empreinte-de-la-session-a', 'nom_filiere' => 'Développement digital', 'date_import_myway' => now(),
            'compte_active_le' => now(), 'etat_compte' => EtatCompte::Actif->value, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    public function test_deux_activations_simultanees_ne_creent_qu_un_seul_compte(): void
    {
        // Deux vérifications ouvertes pour la même personne (deux onglets, deux appareils) : chacune a son jeton.
        $jetonA = $this->verification();
        $jetonB = $this->verification();
        $service = app(ActivationCompte::class);

        // Session A : en cours d'activation — vérifications verrouillées, compte écrit, transaction non encore validée.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('verification_identite')->where('cef', self::CEF)->orderBy('id')->lockForUpdate()->get();
        $a->table('stagiaire')->insert($this->ligneStagiaire());
        $a->table('verification_identite')->where('jeton_hash', VerificationIdentite::empreinte($jetonA))->update(['consommee_le' => now(), 'profil' => null]);

        // Session B : active la même personne avec l'autre jeton. Elle attend le verrou, puis renonce proprement.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $service->activer($jetonB, 'Mon-Internat-2026');
            $this->fail('La seconde activation n\'aurait pas dû aboutir pendant la première.');
        } catch (RegleMetierException $e) {
            $this->assertSame('activation_en_cours', $e->codeRegle);
            $this->assertSame(409, $e->statutHttp);
        }

        // A valide. B réessaie : le compte existe, il n'est ni dupliqué ni écrasé.
        $a->commit();

        try {
            $service->activer($jetonB, 'Mon-Internat-2026');
            $this->fail('Le compte est déjà activé.');
        } catch (RegleMetierException $e) {
            $this->assertSame('compte_existant', $e->codeRegle);
        }

        $comptes = Stagiaire::where('cef', self::CEF)->get();
        $this->assertCount(1, $comptes);
        $this->assertSame('empreinte-de-la-session-a', $comptes[0]->mot_de_passe_hash);
    }

    public function test_deux_activations_successives_avec_deux_jetons_valides_ne_creent_qu_un_compte(): void
    {
        $jetonA = $this->verification();
        $jetonB = $this->verification();
        $service = app(ActivationCompte::class);

        $compte = $service->activer($jetonA, 'Mon-Internat-2026');

        // L'activation ferme toutes les autorisations ouvertes de la même personne.
        try {
            $service->activer($jetonB, 'Autre-Internat-2026');
            $this->fail('La seconde autorisation aurait dû être fermée.');
        } catch (RegleMetierException $e) {
            $this->assertSame('jeton_expire', $e->codeRegle);
        }

        $this->assertSame(1, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::enCours()->count());
        $this->assertSame($compte->mot_de_passe_hash, Stagiaire::sole()->mot_de_passe_hash);
    }

    public function test_si_les_verrous_etaient_contournes_les_index_uniques_arretent_le_doublon(): void
    {
        // Deux sessions insèrent le même stagiaire sans aucun verrou applicatif : la base sérialise sur l'index unique.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('stagiaire')->insert($this->ligneStagiaire());

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $refuse = false;

        try {
            DB::table('stagiaire')->insert($this->ligneStagiaire());
        } catch (QueryException) {
            $refuse = true; // attente du verrou d'index dépassée : l'insertion concurrente n'aboutit pas
        }

        $a->commit();

        if (! $refuse) {
            $this->fail('La seconde insertion aurait dû être bloquée par l\'index unique.');
        }

        // Même CEF, autre adresse ; même adresse, autre CEF : refusés tous les deux.
        foreach ([['email' => 'autre@exemple.test'], ['cef' => '2026900000042']] as $variante) {
            try {
                DB::table('stagiaire')->insert($variante + $this->ligneStagiaire());
                $this->fail('Doublon accepté.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(1, Stagiaire::count());
            }
        }
    }
}
