<?php

namespace Tests\Feature;

use App\Domain\Identity\ControleInscription;
use App\Enums\StatutMenu;
use App\Enums\StatutVerificationInscription;
use App\Enums\TypeRepas;
use App\Livewire\Personnel\TableauDeBord;
use App\Models\Menu as Repas;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Support\Affichage\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Chaque rôle ouvre son tableau de bord et toutes les entrées de son menu, et
 * aucune autre. Le menu n'est qu'un reflet des permissions : ce test garantit
 * qu'il ne promet jamais un écran que la route refuserait, et qu'un écran
 * absent du menu d'un rôle lui reste fermé.
 */
class NavigationParRoleTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();

        // Un peu de matière pour que les tableaux de bord aient quelque chose à compter.
        $campagne = $this->creerCampagne($this->filles);
        $this->creerResident($campagne, $this->creerLits($this->filles)->first());
        $this->creerDossierRecu($campagne);
        Repas::create([
            'internat_id' => $this->filles->id, 'date_repas' => now()->toDateString(), 'type_repas' => TypeRepas::Dejeuner,
            'description' => 'Couscous du vendredi', 'statut' => StatutMenu::Propose, 'cree_par' => $this->restauration->id,
        ]);
    }

    /** @return array<string, Utilisateur> */
    private function comptes(): array
    {
        return [
            'administrateur' => $this->admin, 'gestionnaire' => $this->gestionnaireFilles, 'chef de pôle' => $this->chefDePole,
            'restauration' => $this->restauration, 'directeur' => $this->directeur,
            'direction régionale' => $this->directionRegionale, 'direction centrale' => $this->directionCentrale,
        ];
    }

    /** @return list<string> chemins du menu d'un compte */
    private function chemins(Utilisateur $compte): array
    {
        return collect(Menu::personnel($compte))->flatMap(fn (array $section) => array_column($section['entrees'], 'chemin'))->values()->all();
    }

    public function test_chaque_role_ouvre_toutes_les_entrees_de_son_menu_et_aucune_autre(): void
    {
        $tous = collect($this->comptes())->flatMap(fn (Utilisateur $compte) => $this->chemins($compte))->unique()->values();
        // Les écrans du personnel sont tous atteints par au moins un rôle.
        $this->assertGreaterThanOrEqual(25, $tous->count());

        foreach ($this->comptes() as $role => $compte) {
            $permis = $this->chemins($compte);
            $this->assertContains('/backend/tableau-de-bord', $permis);

            foreach ($tous as $chemin) {
                $reponse = $this->connecter($compte)->get($chemin);

                in_array($chemin, $permis, true)
                    ? $reponse->assertOk()
                    : $this->assertSame(403, $reponse->status(), "Le rôle « {$role} » ne devrait pas ouvrir {$chemin}.");
            }
        }
    }

    public function test_chaque_role_a_son_tableau_de_bord(): void
    {
        $attendus = [
            'administrateur' => ['Administration', 'Cohérence des données', 'Comptes par rôle'],
            'gestionnaire' => ['Internat filles', 'Occupation par bâtiment', 'Dossiers reçus à instruire'],
            'chef de pôle' => ['Campagnes en cours', 'Capacité des internats'],
            'restauration' => ['Menus proposés à valider', 'Couscous du vendredi'],
            'directeur' => ['Tableau de bord du complexe', 'Établissements du complexe', 'Établissement de test'],
            'direction régionale' => ['Indicateurs régionaux', 'Établissements de la région', $this->region->nom],
            'direction centrale' => ['Pilotage national', 'Comparaison régionale', 'Historique des campagnes'],
        ];

        foreach ($this->comptes() as $role => $compte) {
            $ecran = Livewire::actingAs($compte, 'web')->test(TableauDeBord::class)->assertOk();

            foreach ($attendus[$role] as $texte) {
                $ecran->assertSee($texte);
            }
        }

        // Les chiffres d'un autre internat n'entrent pas dans le tableau d'un gestionnaire.
        Livewire::actingAs($this->gestionnaireGarcons, 'web')->test(TableauDeBord::class)->assertSee('Internat garçons')->assertSee('Référentiel vide')->assertDontSee('Dossiers reçus à instruire');
    }

    public function test_une_inscription_soumise_apparait_dans_ce_qui_est_a_traiter(): void
    {
        $stagiaire = Stagiaire::factory()->inscription(\App\Enums\StatutInscription::Declaree)->create([
            'etablissement_id' => $this->etablissement->id, 'genre' => $this->filles->genre,
        ]);
        $this->agirEnTantQue($stagiaire);
        app(ControleInscription::class)->soumettre($stagiaire, UploadedFile::fake()->createWithContent('attestation.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF"));

        // Le gestionnaire des filles et le chef de pôle la voient ; le gestionnaire des garçons, non.
        Livewire::actingAs($this->gestionnaireFilles, 'web')->test(TableauDeBord::class)->assertSee('Inscriptions en formation à vérifier');
        Livewire::actingAs($this->chefDePole, 'web')->test(TableauDeBord::class)->assertSee('Inscriptions en formation à vérifier');
        Livewire::actingAs($this->gestionnaireGarcons, 'web')->test(TableauDeBord::class)->assertDontSee('Inscriptions en formation à vérifier');

        // Une fois décidée, elle quitte la liste.
        $this->agirEnTantQue($this->chefDePole);
        app(ControleInscription::class)->decider($stagiaire->verificationsInscription()->latest('id')->firstOrFail(), StatutVerificationInscription::Verifiee, null, $this->chefDePole);
        Livewire::actingAs($this->chefDePole, 'web')->test(TableauDeBord::class)->assertDontSee('Inscriptions en formation à vérifier');
    }

    public function test_un_stagiaire_n_entre_pas_dans_l_espace_du_personnel_ni_l_inverse(): void
    {
        $stagiaire = $this->creerStagiaire($this->filles);

        foreach (['/backend/tableau-de-bord', '/dossiers', '/administration/utilisateurs', '/paiements', '/restauration/menus'] as $chemin) {
            $this->assertContains($this->connecter($stagiaire)->get($chemin)->status(), [302, 403], "Un stagiaire ne devrait pas ouvrir {$chemin}.");
        }

        foreach (['/stagiaire', '/stagiaire/dossier', '/stagiaire/paiements'] as $chemin) {
            $this->assertContains($this->connecter($this->gestionnaireFilles)->get($chemin)->status(), [302, 403], "Le personnel ne devrait pas ouvrir {$chemin}.");
        }

        // Hors session : tout renvoie à la connexion.
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->get('/administration/audit')->assertRedirect(route('backend.connexion'));
        $this->get('/stagiaire/dossier')->assertRedirect(route('connexion'));
    }
}
