<?php

namespace Tests\Feature;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutLit;
use App\Models\Affectation;
use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Deux sessions de base de données réelles se disputent le même lit.
 *
 * Ce test n'est pas enveloppé dans une transaction : les deux connexions
 * doivent voir les mêmes lignes validées. Il nettoie donc lui-même les tables
 * qu'il a remplies.
 */
class AffectationConcurrenteTest extends TestCase
{
    use MondeInternat;

    /** Tables de référence conservées entre les tests. */
    private const CONSERVEES = ['migrations', 'roles', 'permissions', 'role_has_permissions', 'parametre'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => RolesEtPermissionsSeeder::class]);
            RefreshDatabaseState::$migrated = true;
        }

        $this->creerMonde();
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

    public function test_deux_sessions_simultanees_ne_peuvent_pas_attribuer_le_meme_lit(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10]);
        $lit = $this->creerLits($this->filles, 1, 2)[0];
        $premiere = $this->creerConfirme($campagne);
        $seconde = $this->creerConfirme($campagne);
        $service = app(AffectationLits::class);

        // Session A : verrouille le lit et y inscrit la première résidente, sans valider encore.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('lit')->where('id', $lit->id)->lockForUpdate()->first();
        $a->table('affectation')->insert([
            'demande_id' => $premiere->id, 'lit_id' => $lit->id, 'date_debut' => now()->toDateString(),
            'affecte_par' => $this->gestionnaireFilles->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a->table('lit')->where('id', $lit->id)->update(['statut' => StatutLit::Occupe->value]);

        // Session B : tente le même lit pendant que A le tient. Elle attend le verrou, puis renonce proprement.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $service->affecter($seconde, $lit, now(), $this->gestionnaireFilles);
            $this->fail('La seconde session n\'aurait pas dû obtenir le lit.');
        } catch (RegleMetierException $e) {
            $this->assertSame('lit_verrouille', $e->codeRegle);
            $this->assertSame(409, $e->statutHttp);
        }

        // A valide. B réessaie : le lit est maintenant occupé.
        $a->commit();

        try {
            $service->affecter($seconde->fresh(), $lit->fresh(), now(), $this->gestionnaireFilles);
            $this->fail('Le lit est déjà occupé.');
        } catch (RegleMetierException $e) {
            $this->assertSame('lit_occupe', $e->codeRegle);
        }

        // Un seul occupant actif, et c'est la première résidente.
        $actives = Affectation::where('lit_id', $lit->id)->whereNull('date_fin')->get();
        $this->assertCount(1, $actives);
        $this->assertSame($premiere->id, $actives[0]->demande_id);
        $this->assertSame(0, Affectation::where('demande_id', $seconde->id)->count());
    }

    public function test_si_les_verrous_etaient_contournes_l_index_unique_arrete_le_doublon(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10]);
        $lit = $this->creerLits($this->filles, 1, 2)[0];
        $premiere = $this->creerConfirme($campagne);
        $seconde = $this->creerConfirme($campagne);
        $ligne = fn (int $demandeId) => [
            'demande_id' => $demandeId, 'lit_id' => $lit->id, 'date_debut' => now()->toDateString(),
            'affecte_par' => $this->gestionnaireFilles->id, 'created_at' => now(), 'updated_at' => now(),
        ];

        // Deux sessions insèrent sans aucun verrou applicatif : la base sérialise sur l'index unique.
        $a = DB::connection('mysql_concurrent');
        $a->beginTransaction();
        $a->table('affectation')->insert($ligne($premiere->id));

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $refuse = false;

        try {
            DB::table('affectation')->insert($ligne($seconde->id));
        } catch (QueryException) {
            $refuse = true; // attente du verrou d'index dépassée : l'insertion concurrente n'aboutit pas
        }

        $a->commit();

        if (! $refuse) {
            $this->fail('La seconde insertion aurait dû être bloquée par l\'index unique.');
        }

        try {
            DB::table('affectation')->insert($ligne($seconde->id));
            $this->fail('Doublon accepté.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, Affectation::where('lit_id', $lit->id)->whereNull('date_fin')->count());
        }
    }
}
