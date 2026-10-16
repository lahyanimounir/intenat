<?php

namespace Tests\Feature;

use App\Domain\Audit\Journal;
use App\Domain\Identity\ComptesPersonnel;
use App\Domain\Identity\Perimetre;
use App\Domain\Identity\Permissions;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\ReglagesMetier;
use App\Domain\Shared\StructureInstitutionnelle;
use App\Enums\RoleCode;
use App\Models\JournalAudit;
use App\Models\Utilisateur;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Journal d'audit (ajout seul, sans donnée sensible) et administration
 * des comptes, des rôles, de l'organisation et des paramètres.
 */
class AuditEtAdministrationTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_le_journal_d_audit_ne_peut_etre_ni_modifie_ni_supprime(): void
    {
        $entree = app(Journal::class)->consigner('test.action', 'demande', 1, ['motif' => 'essai'], $this->admin);

        // Par le modèle.
        try {
            $entree->update(['action' => 'falsifiee']);
            $this->fail('La modification aurait dû être refusée.');
        } catch (LogicException) {
        }

        try {
            $entree->delete();
            $this->fail('La suppression aurait dû être refusée.');
        } catch (LogicException) {
        }

        // Par une requête directe : la base elle-même refuse (déclencheurs).
        foreach ([
            fn () => DB::table('journal_audit')->where('id', $entree->id)->update(['action' => 'falsifiee']),
            fn () => DB::table('journal_audit')->where('id', $entree->id)->delete(),
        ] as $tentative) {
            try {
                $tentative();
                $this->fail('La base aurait dû refuser l\'opération.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('journal d\'audit', $e->getMessage());
            }
        }

        $this->assertSame('test.action', JournalAudit::findOrFail($entree->id)->action);
    }

    public function test_les_donnees_sensibles_ne_sont_jamais_ecrites_dans_le_journal(): void
    {
        $entree = app(Journal::class)->consigner('test.sensible', 'utilisateur', 1, [
            'email' => 'a@b.test', 'mot_de_passe' => 'Secret-123456', 'apres' => ['mot_de_passe_hash' => '$2y$abc', 'nom' => 'Alaoui'], 'recu_fichier' => 'recus/1/x.pdf',
        ], $this->admin);

        $this->assertSame('[masqué]', $entree->details['mot_de_passe']);
        $this->assertSame('[masqué]', $entree->details['apres']['mot_de_passe_hash']);
        $this->assertSame('[masqué]', $entree->details['recu_fichier']);
        $this->assertSame('Alaoui', $entree->details['apres']['nom']);
        $this->assertStringNotContainsString('Secret-123456', (string) json_encode($entree->details));
    }

    public function test_la_base_des_roles_correspond_a_la_matrice_centralisee(): void
    {
        foreach (Permissions::matrice() as $role => $permissions) {
            $enBase = Role::findByName($role, 'web')->permissions->pluck('name')->all();
            $this->assertEqualsCanonicalizing($permissions, $enBase, "Rôle {$role}");
        }

        // Moindre privilège : quelques séparations de pouvoirs essentielles.
        $peut = fn (Utilisateur $u, string $p) => $u->can($p);
        $this->assertTrue($peut($this->chefDePole, 'admissions.deliberer'));
        $this->assertFalse($peut($this->chefDePole, 'criteres.approuver_cmc'));
        $this->assertFalse($peut($this->chefDePole, 'paiements.controler'));
        $this->assertFalse($peut($this->gestionnaireFilles, 'admissions.consulter'));
        $this->assertFalse($peut($this->gestionnaireFilles, 'paiements.autoriser'));
        $this->assertFalse($peut($this->directeur, 'admissions.consulter'));
        $this->assertFalse($peut($this->admin, 'demandes.consulter'));
        $this->assertFalse($peut($this->admin, 'admissions.deliberer'));
    }

    public function test_la_commande_d_installation_cree_le_premier_administrateur(): void
    {
        $avant = Utilisateur::count();

        $this->artisan('internat:creer-administrateur', ['email' => 'Premier.Admin@internat.test', '--nom' => 'Alaoui', '--prenom' => 'Yasmine'])
            ->expectsQuestion('Mot de passe du compte', 'Installation-2026')
            ->expectsQuestion('Confirmez le mot de passe', 'Installation-2026')
            ->expectsOutputToContain('Compte administrateur créé')
            ->assertExitCode(0);

        $compte = Utilisateur::where('email', 'premier.admin@internat.test')->firstOrFail();
        $this->assertTrue($compte->aLeRole(RoleCode::Admin));
        $this->assertTrue($compte->actif);
        $this->assertTrue(Hash::check('Installation-2026', $compte->mot_de_passe_hash));

        // La création est tracée, sans le mot de passe.
        $trace = JournalAudit::where('action', 'utilisateur.creation')->where('entite_id', $compte->id)->firstOrFail();
        $this->assertStringNotContainsString('Installation-2026', json_encode($trace->details));

        // Mot de passe trop faible, puis adresse déjà prise : rien n'est créé.
        $this->artisan('internat:creer-administrateur', ['email' => 'second.admin@internat.test'])
            ->expectsQuestion('Mot de passe du compte', 'court')
            ->expectsQuestion('Confirmez le mot de passe', 'court')
            ->assertExitCode(1);
        $this->artisan('internat:creer-administrateur', ['email' => 'premier.admin@internat.test'])
            ->expectsQuestion('Mot de passe du compte', 'Installation-2026')
            ->expectsQuestion('Confirmez le mot de passe', 'Installation-2026')
            ->assertExitCode(1);

        $this->assertSame($avant + 1, Utilisateur::count());
    }

    public function test_les_actions_d_administration_figurent_au_journal_et_aucune_route_n_y_ecrit(): void
    {
        $this->agirEnTantQue($this->admin);
        $compte = app(ComptesPersonnel::class)->creer([
            'nom' => 'Bennani', 'prenom' => 'Leila', 'email' => 'leila@test.local', 'role' => 'GESTIONNAIRE',
            'internat_id' => $this->filles->id, 'mot_de_passe' => 'Motdepasse-Neuf-1',
        ], $this->admin);

        $trace = JournalAudit::where('action', 'utilisateur.creation')->where('entite_id', $compte->id)->sole();
        $this->assertSame([$this->admin->id, 'GESTIONNAIRE'], [$trace->utilisateur_id, $trace->details['role']]);
        $this->assertStringNotContainsString('Motdepasse-Neuf-1', (string) json_encode(JournalAudit::all()->toArray()));

        // Le journal n'a qu'une route, de lecture : aucune adresse ne permet d'y écrire.
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit') && ! str_starts_with($route->uri(), 'api/'));
        $this->assertSame(['administration/audit'], $routes->map->uri()->values()->all());
        $this->assertSame([], array_values(array_diff($routes->flatMap->methods()->unique()->all(), ['GET', 'HEAD'])));
        $this->connecter($this->admin)->delete('/administration/audit/1')->assertNotFound();
    }

    public function test_un_compte_du_personnel_ne_garde_que_le_perimetre_de_son_role(): void
    {
        $this->agirEnTantQue($this->admin);
        $comptes = app(ComptesPersonnel::class);
        $base = ['nom' => 'Tazi', 'prenom' => 'Omar', 'email' => 'omar@test.local', 'mot_de_passe' => 'Motdepasse-Neuf-1'];

        // Les règles de saisie exigent le niveau du rôle : un gestionnaire sans internat est refusé.
        $regles = ComptesPersonnel::regles(null, $base + ['role' => 'GESTIONNAIRE']);
        $this->assertTrue(validator($base + ['role' => 'GESTIONNAIRE'], $regles)->errors()->has('internat_id'));
        $this->assertTrue(validator(['mot_de_passe' => 'court'] + $base + ['role' => 'ADMIN'], ComptesPersonnel::regles(null, ['role' => 'ADMIN']))->errors()->has('mot_de_passe'));
        $this->assertTrue(validator(['email' => $this->chefDePole->email] + $base + ['role' => 'ADMIN'], ComptesPersonnel::regles(null, ['role' => 'ADMIN']))->errors()->has('email'));

        // Les niveaux étrangers au rôle sont remis à vide : un chef de pôle ne garde pas d'internat.
        $compte = $comptes->creer($base + [
            'role' => 'CHEF_POLE', 'etablissement_id' => $this->etablissement->id, 'internat_id' => $this->filles->id, 'region_id' => $this->region->id,
        ], $this->admin);
        $this->assertSame([$this->etablissement->id, null, null], [$compte->etablissement_id, $compte->internat_id, $compte->region_id]);
        $this->assertNotSame('Motdepasse-Neuf-1', $compte->mot_de_passe_hash);

        // Changement de rôle : le périmètre suit, et le changement est tracé.
        $comptes->modifier($compte, ['role' => 'GESTIONNAIRE', 'internat_id' => $this->garcons->id] + $base, $this->admin);
        $compte = $compte->fresh()->load('roles');
        $this->assertSame([RoleCode::Gestionnaire, $this->garcons->id, null], [$compte->roleCode(), $compte->internat_id, $compte->etablissement_id]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'utilisateur.modification', 'entite_id' => $compte->id]);

        // Désactivation sans suppression ; le compte désactivé perd tout accès.
        $this->assertFalse($comptes->basculer($compte, $this->admin)->actif);
        $this->assertSame([], app(Perimetre::class)->internatIds($compte->fresh()));
        $this->connecter($compte->fresh())->get('/dossiers')->assertRedirect(route('connexion'));
    }

    public function test_un_administrateur_ne_peut_pas_se_retirer_ses_droits_ni_supprimer_le_dernier_administrateur(): void
    {
        $this->agirEnTantQue($this->admin);
        $comptes = app(ComptesPersonnel::class);
        $moi = ['nom' => $this->admin->nom, 'prenom' => $this->admin->prenom, 'email' => $this->admin->email];

        $this->assertRegle('auto_modification', fn () => $comptes->modifier($this->admin, $moi + ['role' => 'DIR_CENTRALE'], $this->admin));
        $this->assertRegle('auto_modification', fn () => $comptes->basculer($this->admin, $this->admin));

        // Seul administrateur : un autre compte ne peut ni le désactiver ni changer son rôle.
        $second = Utilisateur::factory()->role(RoleCode::Admin)->create(['actif' => false]);
        $this->assertRegle('dernier_administrateur', fn () => $comptes->basculer($this->admin, $second));
        $this->assertRegle('dernier_administrateur', fn () => $comptes->modifier($this->admin, $moi + ['role' => 'DIR_CENTRALE'], $second));

        // Avec un second administrateur actif, le premier peut être désactivé ; il en reste toujours un.
        $second->forceFill(['actif' => true])->save();
        $comptes->basculer($this->admin, $second);
        $this->assertSame(1, Utilisateur::role('ADMIN')->where('actif', true)->count());
    }

    public function test_l_organisation_et_les_parametres_obeissent_a_leurs_regles(): void
    {
        $this->agirEnTantQue($this->admin);
        $structure = app(StructureInstitutionnelle::class);

        $region = $structure->enregistrer('regions', null, ['code' => 'NOUV', 'nom' => 'Nouvelle région']);
        $this->assertDatabaseHas('journal_audit', ['action' => 'organisation.creation', 'entite' => 'region', 'entite_id' => $region->id]);

        // Un établissement a un seul internat par genre ; un élément utilisé ne se supprime pas.
        $saisie = ['etablissement_id' => $this->etablissement->id, 'genre' => 'fille', 'nom' => 'Second internat filles'];
        $this->assertTrue(validator($saisie, StructureInstitutionnelle::regles('internats', null, $saisie))->errors()->has('genre'));
        $this->assertRegle('element_utilise', fn () => $structure->supprimer($this->etablissement));
        $this->assertNotNull($this->etablissement->fresh());

        // Paramètre : type contrôlé, modification tracée avec l'ancienne et la nouvelle valeur.
        $this->assertTrue(validator(['v' => 'dix'], ['v' => ReglagesMetier::regles('confirmation.delai_admis_jours')])->fails());
        $this->assertTrue(validator(['v' => 'tirage_au_sort'], ['v' => ReglagesMetier::regles('classement.departage')])->fails());

        app(ReglagesMetier::class)->modifier('confirmation.delai_admis_jours', 12, false, $this->admin);
        $trace = JournalAudit::where('action', 'parametre.modification')->latest('id')->first();
        $this->assertEquals(['cle' => 'confirmation.delai_admis_jours', 'avant' => 10, 'apres' => 12, 'a_valider' => false], $trace->details);
        $this->assertSame(12, app(ReglagesMetier::class)->catalogue()['confirmation.delai_admis_jours']['valeur']);
        $this->assertSame(12, app(Parametres::class)->entier('confirmation.delai_admis_jours'));
    }
}
