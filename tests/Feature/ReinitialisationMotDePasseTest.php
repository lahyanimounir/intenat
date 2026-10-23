<?php

namespace Tests\Feature;

use App\Domain\Identity\ReinitialisationMotDePasse;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\RoleCode;
use App\Livewire\Administration\ComptesStagiaires;
use App\Livewire\Administration\Utilisateurs;
use App\Livewire\Auth\ChangementMotDePasse;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\JournalAudit;
use App\Models\NotificationInterne;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Réinitialisation des mots de passe par les responsables, selon la hiérarchie et le périmètre :
 * directeur → chefs de pôle et gestionnaires de son complexe ; chef de pôle → gestionnaires et
 * stagiaires de son établissement ; gestionnaires d'internat et de restauration → stagiaires.
 */
class ReinitialisationMotDePasseTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Utilisateur $directeurComplexe;

    private Utilisateur $chefVoisin;

    private Utilisateur $gestionnaireVoisin;

    private Stagiaire $stagiaireFille;

    private Stagiaire $stagiaireGarcon;

    private Stagiaire $stagiaireAilleurs;

    private Stagiaire $stagiaireVoisin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();

        // Un second établissement du même complexe, avec son chef de pôle et son internat.
        $voisin = Etablissement::factory()->create(['complexe_id' => $this->etablissement->complexe_id]);
        $internatVoisin = Internat::factory()->create(['etablissement_id' => $voisin->id, 'genre' => Genre::Fille]);
        $this->chefVoisin = Utilisateur::factory()->chefDePole($voisin->id)->create();
        $this->gestionnaireVoisin = Utilisateur::factory()->gestionnaire($internatVoisin)->create();
        $this->directeurComplexe = Utilisateur::factory()->state(['complexe_id' => $this->etablissement->complexe_id])->role(RoleCode::Directeur)->create();

        $this->stagiaireFille = $this->creerStagiaire($this->filles);
        $this->stagiaireGarcon = $this->creerStagiaire($this->garcons);
        $this->stagiaireAilleurs = $this->creerStagiaire($this->ailleurs);
        $this->stagiaireVoisin = $this->creerStagiaire($internatVoisin);
    }

    private function service(): ReinitialisationMotDePasse
    {
        return app(ReinitialisationMotDePasse::class);
    }

    private function peut(Utilisateur $responsable, Utilisateur|Stagiaire $compte): bool
    {
        app(\App\Domain\Identity\Perimetre::class)->oublier();

        return $this->service()->peutReinitialiser($responsable, $compte);
    }

    public function test_la_matrice_hierarchique_est_respectee(): void
    {
        $autreDirecteur = Utilisateur::factory()->state(['complexe_id' => $this->ailleurs->etablissement->complexe_id])->role(RoleCode::Directeur)->create();
        $chefAilleurs = Utilisateur::factory()->chefDePole($this->ailleurs->etablissement_id)->create();

        // Directeur : chefs de pôle, gestionnaires et restauration de son complexe, rien d'autre.
        foreach ([$this->chefDePole, $this->chefVoisin, $this->gestionnaireFilles, $this->gestionnaireVoisin, $this->restauration] as $compte) {
            $this->assertTrue($this->peut($this->directeurComplexe, $compte), 'directeur → '.$compte->roleCode()->value);
        }
        foreach ([$this->admin, $autreDirecteur, $this->directeur, $chefAilleurs, $this->gestionnaireAilleurs, $this->directionRegionale, $this->stagiaireFille] as $compte) {
            $this->assertFalse($this->peut($this->directeurComplexe, $compte));
        }

        // Chef de pôle : gestionnaires, restauration et stagiaires de son établissement ; ni chef de pôle ni directeur.
        foreach ([$this->gestionnaireFilles, $this->gestionnaireGarcons, $this->restauration, $this->stagiaireFille, $this->stagiaireGarcon] as $compte) {
            $this->assertTrue($this->peut($this->chefDePole, $compte));
        }
        foreach ([$this->chefVoisin, $this->directeurComplexe, $this->admin, $this->gestionnaireVoisin, $this->gestionnaireAilleurs, $this->stagiaireVoisin, $this->stagiaireAilleurs] as $compte) {
            $this->assertFalse($this->peut($this->chefDePole, $compte));
        }

        // Gestionnaire d'internat : les seuls stagiaires de son internat (établissement et genre).
        $this->assertTrue($this->peut($this->gestionnaireFilles, $this->stagiaireFille));
        foreach ([$this->stagiaireGarcon, $this->stagiaireAilleurs, $this->stagiaireVoisin, $this->gestionnaireGarcons, $this->restauration, $this->chefDePole] as $compte) {
            $this->assertFalse($this->peut($this->gestionnaireFilles, $compte));
        }

        // Responsable restauration : les stagiaires de son établissement, aucun compte du personnel.
        foreach ([$this->stagiaireFille, $this->stagiaireGarcon] as $compte) {
            $this->assertTrue($this->peut($this->restauration, $compte));
        }
        foreach ([$this->stagiaireAilleurs, $this->stagiaireVoisin, $this->gestionnaireFilles, $this->chefDePole] as $compte) {
            $this->assertFalse($this->peut($this->restauration, $compte));
        }

        // Administrateur : tout compte, sauf le sien. Directions régionale et centrale : aucun.
        foreach ([$this->directeurComplexe, $this->chefDePole, $this->gestionnaireAilleurs, $this->directionCentrale, $this->stagiaireAilleurs] as $compte) {
            $this->assertTrue($this->peut($this->admin, $compte));
        }
        $this->assertFalse($this->peut($this->admin, $this->admin));
        $this->assertFalse($this->peut($this->directionRegionale, $this->stagiaireFille));
        $this->assertFalse($this->peut($this->directionCentrale, $this->gestionnaireFilles));
    }

    public function test_le_directeur_reinitialise_depuis_utilisateurs_sans_autre_droit(): void
    {
        $ecran = $this->ecran($this->directeurComplexe, Utilisateurs::class)
            ->assertSee($this->chefVoisin->email)->assertSee($this->gestionnaireFilles->email)
            ->assertDontSee($this->admin->email)->assertDontSee($this->gestionnaireAilleurs->email)->assertDontSee('Nouveau compte');
        $this->assertEqualsCanonicalizing(
            [$this->chefDePole->id, $this->chefVoisin->id, $this->gestionnaireFilles->id, $this->gestionnaireGarcons->id, $this->gestionnaireVoisin->id, $this->restauration->id],
            $ecran->viewData('reinitialisables'),
        );

        // Hors de sa responsabilité ou hors de son rôle : refusé côté serveur, même par appel direct.
        $this->ecran($this->directeurComplexe, Utilisateurs::class)->call('preparerReinitialisation', $this->admin->id)->assertForbidden();
        $this->ecran($this->directeurComplexe, Utilisateurs::class)->call('modifier', $this->chefDePole->id)->assertForbidden();
        $this->ecran($this->directeurComplexe, Utilisateurs::class)->call('basculer', $this->chefDePole->id)->assertForbidden();

        // Mot de passe généré : affiché une fois, haché, provisoire ; l'ancien ne fonctionne plus.
        $ecran = $this->ecran($this->directeurComplexe, Utilisateurs::class)->call('preparerReinitialisation', $this->chefDePole->id)
            ->assertSet('dialogue', 'mot-de-passe')->assertSee('Chef de pôle')->call('reinitialiserMotDePasse')->assertHasNoErrors();
        $provisoire = $ecran->get('provisoire');
        $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{14}$/', $provisoire);
        $ecran->assertSee($provisoire)->call('fermerReinitialisation')->assertSet('provisoire', null);

        $chef = $this->chefDePole->fresh();
        $this->assertTrue(Hash::check($provisoire, $chef->mot_de_passe_hash));
        $this->assertNotSame($provisoire, $chef->getRawOriginal('mot_de_passe_hash'));
        $this->assertTrue($chef->mot_de_passe_provisoire);

        // Traçabilité : responsable, compte, date — jamais le mot de passe.
        $trace = JournalAudit::where('action', 'compte.mot_de_passe_reinitialise')->sole();
        $this->assertSame([$this->directeurComplexe->id, 'utilisateur', $this->chefDePole->id], [$trace->utilisateur_id, $trace->entite, $trace->entite_id]);
        $this->assertNotNull($trace->date_heure);
        $this->assertStringNotContainsString($provisoire, (string) json_encode($trace->getAttributes()));
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('titre', 'Mot de passe réinitialisé')->exists());
    }

    public function test_le_chef_de_pole_et_les_gestionnaires_reinitialisent_les_stagiaires_de_leur_perimetre(): void
    {
        // Chef de pôle : les stagiaires de son établissement, filles et garçons.
        $this->ecran($this->chefDePole, ComptesStagiaires::class)
            ->assertSee($this->stagiaireFille->nom)->assertSee($this->stagiaireGarcon->nom)->assertDontSee($this->stagiaireAilleurs->nom)
            ->assertDontSee('Suspendre');

        // Gestionnaire : mot de passe choisi, conforme à la politique ; les stagiaires d'un autre internat restent hors d'atteinte.
        $ecran = $this->ecran($this->gestionnaireFilles, ComptesStagiaires::class)
            ->assertSee($this->stagiaireFille->nom)->assertDontSee($this->stagiaireGarcon->nom)
            ->call('preparerReinitialisation', $this->stagiaireFille->id)->set('modeMdp', 'saisi')
            ->set('mot_de_passe', 'court')->set('mot_de_passe_confirmation', 'court')->call('reinitialiserMotDePasse')->assertHasErrors('mot_de_passe');
        $ecran->set('mot_de_passe', 'Provisoire-2026A')->set('mot_de_passe_confirmation', 'Provisoire-2026A')->call('reinitialiserMotDePasse')
            ->assertHasNoErrors()->assertSet('provisoire', 'Provisoire-2026A');
        $this->assertTrue(Hash::check('Provisoire-2026A', $this->stagiaireFille->fresh()->mot_de_passe_hash));
        $this->assertTrue($this->stagiaireFille->fresh()->mot_de_passe_provisoire);

        $this->ecran($this->gestionnaireFilles, ComptesStagiaires::class)->call('preparerReinitialisation', $this->stagiaireGarcon->id)->assertForbidden();
        $this->ecran($this->gestionnaireFilles, ComptesStagiaires::class)->call('suspendre', $this->stagiaireFille->id, 'Motif quelconque')->assertForbidden();
        $this->ecran($this->restauration, ComptesStagiaires::class)->call('preparerReinitialisation', $this->stagiaireAilleurs->id)->assertForbidden();

        // Accès aux écrans : seulement pour les rôles habilités.
        $this->connecter($this->gestionnaireFilles)->get('/administration/utilisateurs')->assertForbidden();
        $this->connecter($this->directionRegionale)->get('/administration/comptes-stagiaires')->assertForbidden();

        // Un compte suspendu ne se réinitialise pas.
        $this->stagiaireGarcon->forceFill(['etat_compte' => EtatCompte::Suspendu])->save();
        $this->assertRegle('compte_inactif', fn () => $this->service()->reinitialiser($this->chefDePole, $this->stagiaireGarcon->fresh()));
    }

    public function test_le_mot_de_passe_provisoire_doit_etre_change_a_la_connexion_suivante(): void
    {
        $provisoire = $this->service()->reinitialiser($this->chefDePole, $this->stagiaireFille);
        $this->service()->reinitialiser($this->directeurComplexe, $this->gestionnaireFilles);

        // Tant qu'il est provisoire, tout ramène au changement, à l'écran comme par l'API.
        $this->connecter($this->stagiaireFille->fresh())->get('/stagiaire/profil')->assertRedirect(route('mot-de-passe.changement'));
        $this->connecter($this->gestionnaireFilles->fresh())->get('/backend/tableau-de-bord')->assertRedirect(route('mot-de-passe.changement'));
        $this->connecter($this->stagiaireFille->fresh())->getJson('/api/stagiaire/tableau-de-bord')->assertForbidden();

        // Le nouveau mot de passe doit différer du provisoire et respecter la politique.
        $ecran = $this->ecran($this->stagiaireFille->fresh(), ChangementMotDePasse::class)
            ->set('mot_de_passe', $provisoire)->set('mot_de_passe_confirmation', $provisoire)->call('enregistrer')->assertHasErrors('mot_de_passe');
        $ecran->set('mot_de_passe', 'Personnel-2026Z')->set('mot_de_passe_confirmation', 'Personnel-2026Z')->call('enregistrer')
            ->assertHasNoErrors()->assertRedirect(route('stagiaire.accueil'));

        $stagiaire = $this->stagiaireFille->fresh();
        $this->assertFalse($stagiaire->mot_de_passe_provisoire);
        $this->assertTrue(Hash::check('Personnel-2026Z', $stagiaire->mot_de_passe_hash));
        $this->connecter($stagiaire)->get('/stagiaire/profil')->assertOk();
    }

    public function test_les_demandes_repetees_sont_freinees(): void
    {
        RateLimiter::clear('reinitialisation-mot-de-passe:'.$this->chefDePole->id);

        for ($i = 0; $i < 20; $i++) {
            $this->service()->reinitialiser($this->chefDePole, $this->stagiaireFille);
        }

        $refus = $this->assertRegle('trop_de_demandes', fn () => $this->service()->reinitialiser($this->chefDePole, $this->stagiaireFille));
        $this->assertSame(429, $refus->statutHttp);
        $this->assertSame(20, JournalAudit::where('action', 'compte.mot_de_passe_reinitialise')->count());
    }
}
