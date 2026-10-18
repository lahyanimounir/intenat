<?php

namespace Tests\Feature;

use App\Domain\Identity\RecuperationCompte;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\RoleCode;
use App\Livewire\Auth\Connexion;
use App\Livewire\Auth\ConnexionPersonnel;
use App\Livewire\Auth\MotDePasseOublie;
use App\Livewire\Auth\Reinitialisation;
use App\Models\JournalAudit;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Récupération d'un compte INTERNAT par lien de réinitialisation, à usage
 * unique et limité dans le temps, pour le personnel comme pour les stagiaires.
 */
class RecuperationCompteTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAU = 'Nouveau-Internat-2026';

    private const ANCIEN = 'Motdepasse-Test-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
    }

    private function demander(string $espace, string $email): Testable
    {
        return Livewire::test(MotDePasseOublie::class, ['espace' => $espace])->set('email', $email)->call('envoyer')->assertHasNoErrors();
    }

    private function reinitialiser(string $espace, string $email, string $jeton, string $motDePasse = self::NOUVEAU): Testable
    {
        return Livewire::test(Reinitialisation::class, ['espace' => $espace])->set('email', $email)->set('mot_de_passe', $motDePasse)->set('mot_de_passe_confirmation', $motDePasse)
            ->call('enregistrer', $jeton);
    }

    private function jetonEnvoye(object $compte): string
    {
        $jeton = null;
        Notification::assertSentTo($compte, ReinitialisationMotDePasse::class, function (ReinitialisationMotDePasse $n) use (&$jeton) {
            $jeton = $n->jeton;

            return true;
        });

        return (string) $jeton;
    }

    private function connexion(string $espace, string $email, string $motDePasse): Testable
    {
        return Livewire::test($espace === 'stagiaire' ? Connexion::class : ConnexionPersonnel::class)->set('email', $email)->set('mot_de_passe', $motDePasse)->call('connecter');
    }

    public function test_un_stagiaire_reinitialise_son_mot_de_passe_internat_par_un_lien_a_usage_unique(): void
    {
        Notification::fake();
        $stagiaire = Stagiaire::factory()->create(['email' => 'salma@exemple.test']);

        $this->demander('stagiaire', 'Salma@Exemple.test');
        $jeton = $this->jetonEnvoye($stagiaire);

        // Le jeton n'est conservé que haché, dans la table des stagiaires.
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertNotSame($jeton, DB::table('stagiaire_password_reset_tokens')->value('token'));

        $this->reinitialiser('stagiaire', 'salma@exemple.test', $jeton)->assertHasNoErrors()->assertRedirect(route('connexion'));

        $stagiaire->refresh();
        $this->assertStringStartsWith('$argon2id$', $stagiaire->mot_de_passe_hash);
        $this->assertNotNull($stagiaire->mot_de_passe_modifie_le);
        $this->assertDatabaseHas('journal_audit', ['action' => 'auth.mot_de_passe_reinitialise', 'stagiaire_id' => $stagiaire->id]);
        $this->assertStringNotContainsString(self::NOUVEAU, (string) json_encode(JournalAudit::all()->toArray()));

        // L'ancien mot de passe ne vaut plus, le nouveau si ; le lien ne se rejoue pas.
        $this->connexion('stagiaire', 'salma@exemple.test', self::ANCIEN)->assertHasErrors('email');
        $this->connexion('stagiaire', 'salma@exemple.test', self::NOUVEAU)->assertHasNoErrors()->assertRedirect(route('stagiaire.accueil'));
        auth('stagiaire')->logout();

        $this->reinitialiser('stagiaire', 'salma@exemple.test', $jeton, 'Encore-Un-Autre-2026')->assertNoRedirect()->assertSee('invalide ou a expiré');
        $this->assertTrue(password_verify(self::NOUVEAU, $stagiaire->fresh()->mot_de_passe_hash));
    }

    public function test_la_demande_ne_revele_pas_si_un_compte_existe(): void
    {
        Notification::fake();
        Stagiaire::factory()->create(['email' => 'connue@exemple.test']);
        Stagiaire::factory()->create(['email' => 'suspendue@exemple.test', 'etat_compte' => EtatCompte::Suspendu]);
        Utilisateur::factory()->role(RoleCode::Gestionnaire)->create(['email' => 'inactif@test.local', 'actif' => false]);

        // À l'écran, la réponse est la même dans tous les cas : seule l'adresse saisie y figure.
        $neutre = fn (Testable $ecran, string $email) => preg_replace('/\s+/', ' ', str_replace($email, '…', strip_tags((string) $ecran->assertSet('envoyeA', $email)->html())));

        $connue = $neutre($this->demander('stagiaire', 'connue@exemple.test'), 'connue@exemple.test');
        $this->assertSame($connue, $neutre($this->demander('stagiaire', 'inconnue@exemple.test'), 'inconnue@exemple.test'));
        $this->assertSame($connue, $neutre($this->demander('stagiaire', 'suspendue@exemple.test'), 'suspendue@exemple.test'));

        $this->demander('personnel', 'inactif@test.local')->assertSet('envoyeA', 'inactif@test.local');

        // Un seul lien est parti : ni pour l'adresse inconnue, ni pour le compte suspendu, ni pour le compte désactivé.
        Notification::assertCount(1);
        $this->assertSame(1, DB::table('stagiaire_password_reset_tokens')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_un_lien_ne_vaut_que_pour_son_compte_son_espace_et_sa_duree(): void
    {
        Notification::fake();
        $stagiaire = Stagiaire::factory()->create(['email' => 'partagee@exemple.test']);
        $gestionnaire = Utilisateur::factory()->role(RoleCode::Gestionnaire)->create(['email' => 'partagee@exemple.test']);
        $autre = Stagiaire::factory()->create(['email' => 'autre@exemple.test']);

        $this->demander('stagiaire', 'partagee@exemple.test');
        $jeton = $this->jetonEnvoye($stagiaire);
        Notification::assertNotSentTo($gestionnaire, ReinitialisationMotDePasse::class);

        $refuse = function (string $espace, string $email) use ($jeton) {
            try {
                app(RecuperationCompte::class)->reinitialiser($espace, $email, $jeton, self::NOUVEAU);
                $this->fail('Le lien aurait dû être refusé.');
            } catch (RegleMetierException $regle) {
                $this->assertSame('lien_invalide', $regle->codeRegle);
            }
        };

        // Même adresse côté personnel, ou autre stagiaire : le jeton ne s'applique pas.
        $refuse('personnel', 'partagee@exemple.test');
        $refuse('stagiaire', 'autre@exemple.test');
        // Un mot de passe trop faible est refusé avant même d'examiner le lien.
        $this->reinitialiser('stagiaire', 'partagee@exemple.test', $jeton, 'faible')->assertHasErrors('mot_de_passe');

        // Passé le délai, le lien est périmé.
        $this->travel((int) config('auth.passwords.stagiaires.expire') + 1)->minutes();
        $refuse('stagiaire', 'partagee@exemple.test');

        foreach ([$stagiaire, $gestionnaire, $autre] as $compte) {
            $this->assertTrue(password_verify(self::ANCIEN, $compte->fresh()->mot_de_passe_hash));
        }
    }

    public function test_le_personnel_reinitialise_son_mot_de_passe_et_ses_autres_sessions_sont_fermees(): void
    {
        Notification::fake();
        $admin = Utilisateur::factory()->role(RoleCode::Admin)->create(['email' => 'admin@test.local']);

        // Une session est ouverte sur un autre poste avant la réinitialisation.
        $this->connexion('personnel', 'admin@test.local', self::ANCIEN)->assertRedirect(route('tableau-de-bord'));
        $this->get(route('tableau-de-bord'))->assertOk();
        $empreinte = $admin->fresh()->mot_de_passe_hash;

        $this->demander('personnel', 'admin@test.local');
        app(RecuperationCompte::class)->reinitialiser('personnel', 'admin@test.local', $this->jetonEnvoye($admin), self::NOUVEAU);

        // L'empreinte a changé : la session ouverte avec l'ancien mot de passe n'est plus acceptée.
        $this->assertNotSame($empreinte, $admin->fresh()->mot_de_passe_hash);
        $this->app['auth']->forgetGuards();
        $this->get(route('tableau-de-bord'))->assertRedirect(route('backend.connexion'));

        $this->connexion('personnel', 'admin@test.local', self::ANCIEN)->assertHasErrors('email');
        $this->connexion('personnel', 'admin@test.local', self::NOUVEAU)->assertHasNoErrors()->assertRedirect(route('tableau-de-bord'));
        $this->assertDatabaseHas('journal_audit', ['action' => 'auth.mot_de_passe_reinitialise', 'utilisateur_id' => $admin->id]);
    }

    public function test_le_lien_envoye_ne_place_le_jeton_dans_aucune_adresse_transmise_a_un_serveur(): void
    {
        $notification = new ReinitialisationMotDePasse('jeton-de-test', 'stagiaire');
        $lien = $notification->lien();

        // Le jeton voyage dans le fragment : ni chemin, ni paramètres de requête.
        $this->assertSame('/reinitialisation', parse_url($lien, PHP_URL_PATH));
        $this->assertNull(parse_url($lien, PHP_URL_QUERY));
        $this->assertSame('jeton=jeton-de-test', parse_url($lien, PHP_URL_FRAGMENT));

        // Le lien du personnel mène à la page de son propre espace.
        $this->assertSame('/backend/reinitialisation', parse_url((new ReinitialisationMotDePasse('jeton-de-test', 'personnel'))->lien(), PHP_URL_PATH));

        // En file d'attente, la charge est chiffrée : le jeton n'est pas lisible dans la table des tâches.
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertStringContainsString('30 minutes', (string) $notification->toMail(new Stagiaire)->render());
    }
}
