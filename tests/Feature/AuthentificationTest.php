<?php

namespace Tests\Feature;

use App\Enums\EtatCompte;
use App\Enums\RoleCode;
use App\Livewire\Auth\Connexion;
use App\Livewire\Auth\ConnexionPersonnel;
use App\Models\JournalAudit;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Connexion du personnel et des stagiaires : toujours locale, sur le mot de
 * passe INTERNAT, sans aucun service extérieur. La création du compte d'un
 * stagiaire est couverte par InscriptionStagiaireTest.
 */
class AuthentificationTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'Motdepasse-Test-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
    }

    private function connexion(string $espace, string $email, string $motDePasse): Testable
    {
        return Livewire::test($espace === 'stagiaire' ? Connexion::class : ConnexionPersonnel::class)->set('email', $email)->set('mot_de_passe', $motDePasse)->call('connecter');
    }

    private function fermerLaSession(string $espace = 'personnel'): void
    {
        $this->post(route('deconnexion'))->assertRedirect(route($espace === 'stagiaire' ? 'connexion' : 'backend.connexion'));
        $this->app['auth']->forgetGuards();
    }

    public function test_le_personnel_se_connecte_et_recoit_son_role_et_ses_permissions(): void
    {
        $admin = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);

        // L'adresse n'est pas sensible à la casse.
        $this->connexion('personnel', 'ADMIN@test.local', self::MOT_DE_PASSE)->assertHasNoErrors()->assertRedirect(route('tableau-de-bord'));

        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertNotNull($admin->fresh()->derniere_connexion_le);
        $this->assertDatabaseHas('journal_audit', ['action' => 'auth.connexion', 'utilisateur_id' => $admin->id]);

        // Le menu reflète les permissions du rôle : l'administration, pas les dossiers.
        $this->get(route('tableau-de-bord'))->assertOk()->assertSee('Utilisateurs')->assertSee('Journal d’audit', false)->assertDontSee('Classement');
    }

    public function test_un_mot_de_passe_errone_est_refuse_sans_reveler_si_le_compte_existe(): void
    {
        Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);

        $existant = $this->connexion('personnel', 'admin@test.local', 'faux')->assertHasErrors('email')->assertNoRedirect();
        $inconnu = $this->connexion('personnel', 'personne@test.local', 'faux')->assertHasErrors('email');

        $this->assertSame($existant->errors()->get('email'), $inconnu->errors()->get('email'));
        $this->assertGuest('web');
        // Le mot de passe saisi ne reste pas dans l'état de l'écran.
        $existant->assertSet('mot_de_passe', '');

        // L'échec est tracé, sans le mot de passe saisi.
        $trace = JournalAudit::where('action', 'auth.echec')->first();
        $this->assertSame(['email' => 'admin@test.local'], $trace->details);
    }

    public function test_un_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $inactif = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'inactif@test.local', 'actif' => false]);

        $this->connexion('personnel', 'inactif@test.local', self::MOT_DE_PASSE)->assertNoRedirect();
        $this->assertGuest('web');

        // Désactivé pendant que sa session est ouverte : la requête suivante le ramène à la connexion.
        $actif = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'actif@test.local']);
        $this->connexion('personnel', 'actif@test.local', self::MOT_DE_PASSE)->assertRedirect(route('tableau-de-bord'));
        $this->get(route('tableau-de-bord'))->assertOk();
        $actif->forceFill(['actif' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->get(route('tableau-de-bord'))->assertRedirect(route('backend.connexion'))->assertSessionHas('danger');
        $this->assertGuest('web');
        $this->assertNull($inactif->fresh()->derniere_connexion_le);
    }

    public function test_les_tentatives_de_connexion_sont_limitees(): void
    {
        Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'cible@test.local']);

        foreach (range(1, 5) as $i) {
            $this->connexion('personnel', 'cible@test.local', 'faux')->assertHasErrors('email');
        }

        // Au sixième essai, même le bon mot de passe est refusé : il faut patienter.
        $refus = $this->connexion('personnel', 'cible@test.local', self::MOT_DE_PASSE)->assertHasErrors('email')->assertNoRedirect();
        $this->assertStringContainsString('Trop de tentatives', $refus->errors()->first('email'));
        $this->assertGuest('web');
    }

    public function test_une_route_protegee_exige_une_session(): void
    {
        // Chacun est renvoyé à l'entrée de son espace : « / » pour les stagiaires, « /backend » pour le personnel.
        foreach (['/backend/tableau-de-bord', '/dossiers', '/notifications', '/administration/utilisateurs'] as $chemin) {
            $this->get($chemin)->assertRedirect(route('backend.connexion'));
        }
        $this->get('/stagiaire/dossier')->assertRedirect(route('connexion'));

        // Une action Livewire hors session est refusée elle aussi.
        Livewire::test(\App\Livewire\Personnel\Dossiers::class)->assertForbidden();
    }

    public function test_la_deconnexion_ferme_la_session_de_l_espace_ouvert(): void
    {
        $admin = Utilisateur::factory()->role(RoleCode::Admin)->create();
        $stagiaire = Stagiaire::factory()->create();

        $this->actingAs($admin, 'web')->get(route('tableau-de-bord'))->assertOk();
        $this->fermerLaSession();
        $this->assertGuest('web');
        $this->get(route('tableau-de-bord'))->assertRedirect(route('backend.connexion'));

        $this->actingAs($stagiaire, 'stagiaire')->get('/stagiaire')->assertOk();
        $this->fermerLaSession('stagiaire');
        $this->assertGuest('stagiaire');
        $this->assertDatabaseHas('journal_audit', ['action' => 'auth.deconnexion']);
    }

    public function test_un_mot_de_passe_stagiaire_errone_est_refuse_sans_reveler_si_le_compte_existe(): void
    {
        Stagiaire::factory()->create(['email' => 'connue@exemple.test']);

        $existant = $this->connexion('stagiaire', 'connue@exemple.test', 'faux')->assertHasErrors('email');
        $inconnu = $this->connexion('stagiaire', 'inconnue@exemple.test', 'faux')->assertHasErrors('email');

        $this->assertSame($existant->errors()->all(), $inconnu->errors()->all());
        $this->assertGuest('stagiaire');
    }

    public function test_les_mots_de_passe_sont_haches_en_argon2id_et_les_empreintes_heritees_sont_migrees(): void
    {
        $admin = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);
        $stagiaire = Stagiaire::factory()->create(['email' => 'ancienne@exemple.test']);

        $this->assertStringStartsWith('$argon2id$', $admin->mot_de_passe_hash);
        $this->assertStringStartsWith('$argon2id$', $stagiaire->mot_de_passe_hash);

        // Comptes créés avant le passage à Argon2id : empreinte bcrypt, toujours acceptée…
        $bcrypt = password_hash(self::MOT_DE_PASSE, PASSWORD_BCRYPT, ['cost' => 4]);
        DB::table('utilisateur')->where('id', $admin->id)->update(['mot_de_passe_hash' => $bcrypt]);
        DB::table('stagiaire')->where('id', $stagiaire->id)->update(['mot_de_passe_hash' => $bcrypt]);

        $this->connexion('personnel', 'admin@test.local', self::MOT_DE_PASSE)->assertHasNoErrors()->assertRedirect();
        Auth::guard('web')->logout();
        $this->connexion('stagiaire', 'ancienne@exemple.test', self::MOT_DE_PASSE)->assertHasNoErrors()->assertRedirect(route('stagiaire.accueil'));

        // … et recalculée en Argon2id à la première connexion réussie.
        $this->assertStringStartsWith('$argon2id$', $admin->fresh()->mot_de_passe_hash);
        $this->assertStringStartsWith('$argon2id$', $stagiaire->fresh()->mot_de_passe_hash);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $stagiaire->fresh()->mot_de_passe_hash));
    }

    public function test_un_compte_stagiaire_suspendu_ne_peut_plus_se_connecter_et_sa_session_est_fermee(): void
    {
        $stagiaire = Stagiaire::factory()->create(['email' => 'resident@exemple.test']);

        $this->connexion('stagiaire', 'resident@exemple.test', self::MOT_DE_PASSE)->assertRedirect(route('stagiaire.accueil'));
        $this->get('/stagiaire/profil')->assertOk();

        $stagiaire->forceFill(['etat_compte' => EtatCompte::Suspendu, 'suspendu_le' => now(), 'motif_suspension' => 'Exclusion'])->save();

        // La session déjà ouverte est fermée à la requête suivante.
        $this->app['auth']->forgetGuards();
        $this->get('/stagiaire/profil')->assertRedirect(route('connexion'))->assertSessionHas('danger');
        $this->assertGuest('stagiaire');

        // La suspension n'est annoncée qu'après un mot de passe correct.
        $this->connexion('stagiaire', 'resident@exemple.test', 'faux')->assertHasErrors('email')->assertSet('refus', null);
        $this->connexion('stagiaire', 'resident@exemple.test', self::MOT_DE_PASSE)->assertNoRedirect()->assertSee('Votre compte INTERNAT est suspendu');
        $this->assertGuest('stagiaire');
    }

    public function test_les_tentatives_sur_un_meme_compte_stagiaire_sont_limitees(): void
    {
        Stagiaire::factory()->create(['email' => 'cible@exemple.test']);
        Stagiaire::factory()->create(['email' => 'salma@exemple.test']);

        foreach (range(1, 5) as $i) {
            $this->connexion('stagiaire', 'cible@exemple.test', 'faux')->assertHasErrors('email');
        }

        $this->assertStringContainsString('Trop de tentatives', $this->connexion('stagiaire', 'cible@exemple.test', 'faux')->errors()->first('email'));

        // Un autre compte, depuis le même poste, n'est pas pénalisé : un établissement sort souvent par une seule adresse IP.
        $this->connexion('stagiaire', 'salma@exemple.test', self::MOT_DE_PASSE)->assertHasNoErrors()->assertRedirect(route('stagiaire.accueil'));
    }

    public function test_chaque_espace_a_sa_propre_entree_sans_choix_a_faire(): void
    {
        // Personnel : « /backend », sans onglet ni lien vers l'espace stagiaire.
        $this->get('/backend')->assertOk()->assertSee('Espace personnel')->assertSee(route('backend.mot-de-passe.oubli'), false)
            ->assertDontSee('Créer mon compte')->assertDontSee(route('inscription'), false)->assertDontSee('Stagiaire</', false);
        $this->get('/backend/mot-de-passe-oublie')->assertOk()->assertSee('Espace personnel')->assertDontSee('Créer mon compte');
        $this->get('/backend/reinitialisation')->assertOk()->assertSee(route('backend.connexion'), false);

        // Stagiaires et candidats : « / », avec la connexion, l'inscription et la récupération du mot de passe.
        $this->get('/')->assertOk()->assertSee('Espace stagiaires')->assertSee(route('inscription'), false)
            ->assertSee(route('mot-de-passe.oubli'), false)->assertDontSee('Espace personnel');
        $this->get('/mot-de-passe-oublie')->assertOk()->assertSee('Espace stagiaire');

        // L'ancienne page commune renvoie chacun vers sa propre entrée.
        $this->get('/connexion')->assertRedirect(route('backend.connexion'));
        $this->get('/connexion?espace=stagiaire')->assertRedirect(route('connexion'));

        // Après connexion, le personnel arrive sur le tableau de bord de son rôle ; ouvrir une entrée l'y ramène.
        Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);
        $this->connexion('personnel', 'admin@test.local', self::MOT_DE_PASSE)->assertRedirect(route('tableau-de-bord'));
        $this->get('/backend')->assertRedirect(route('tableau-de-bord'));
        $this->get('/')->assertRedirect(route('tableau-de-bord'));
    }

    public function test_un_stagiaire_ne_peut_pas_entrer_dans_l_espace_du_personnel_et_inversement(): void
    {
        $stagiaire = Stagiaire::factory()->create(['email' => 'stagiaire@exemple.test']);
        $admin = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);

        // Le compte d'un espace n'ouvre pas l'autre, même avec le bon mot de passe.
        $this->connexion('personnel', 'stagiaire@exemple.test', self::MOT_DE_PASSE)->assertHasErrors('email');
        $this->connexion('stagiaire', 'admin@test.local', self::MOT_DE_PASSE)->assertHasErrors('email');
        $this->assertGuest('web');
        $this->assertGuest('stagiaire');

        foreach (['/administration/utilisateurs', '/backend/tableau-de-bord', '/backend'] as $chemin) {
            $this->assertContains($this->actingAs($stagiaire, 'stagiaire')->get($chemin)->status(), [302, 403]);
        }

        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->assertContains($this->actingAs($admin, 'web')->get('/stagiaire/dossier')->status(), [302, 403]);
    }
}
