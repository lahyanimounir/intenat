<?php

namespace Tests\Feature;

use App\Enums\EtatRestitution;
use App\Enums\StatutImport;
use App\Enums\StatutLit;
use App\Livewire\Personnel\Affectations;
use App\Livewire\Personnel\ImportReferentiel;
use App\Livewire\Personnel\Plan;
use App\Livewire\Personnel\ResidentFiche;
use App\Livewire\Personnel\Residents;
use App\Models\Affectation;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\Equipement;
use App\Models\ImportReferentiel as Import;
use App\Models\Lit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Hébergement dans la nouvelle interface : référentiel tenu par le gestionnaire
 * de son seul internat, attribution d'un lit, vie du séjour. Aucune période
 * passée n'est effacée, et un lit ne porte jamais deux occupations actives.
 */
class EcransHebergementTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_le_gestionnaire_tient_le_referentiel_de_son_internat(): void
    {
        $plan = Livewire::actingAs($this->gestionnaireFilles)->test(Plan::class)->assertSee('Référentiel vide');

        $plan->call('preparerBatiment')->set('batiment.code', 'A')->set('batiment.nom', 'Bâtiment A')->set('batiment.nombre_etages', 1)->call('enregistrerBatiment')->assertHasNoErrors();
        $batiment = Batiment::sole();
        $this->assertSame($this->filles->id, $batiment->internat_id);

        // Un code déjà pris dans l'internat est refusé ; un étage au-delà du bâtiment aussi.
        $plan->call('preparerBatiment')->set('batiment.code', 'A')->set('batiment.nom', 'Doublon')->call('enregistrerBatiment');
        $this->assertSame(1, Batiment::count());

        $plan->call('preparerChambre', $batiment->id)->set('chambre.numero', '101')->set('chambre.etage', 5)->call('enregistrerChambre')->assertHasErrors('chambre.etage');
        $plan->set('chambre.etage', 1)->set('chambre.capacite', 1)->call('enregistrerChambre')->assertHasNoErrors();
        $chambre = Chambre::sole();

        $plan->call('preparerLit', $chambre->id)->set('numeroLit', '1')->call('ajouterLit')->assertHasNoErrors();
        $lit = Lit::sole();

        // La capacité de la chambre borne le nombre de lits.
        $plan->call('preparerLit', $chambre->id)->set('numeroLit', '2')->call('ajouterLit');
        $this->assertSame(1, Lit::count());

        $plan->call('voirLit', $lit->id)->call('basculerLit', true);
        $this->assertSame(StatutLit::HorsService, $lit->fresh()->statut);
        $plan->call('basculerLit', false);
        $this->assertSame(StatutLit::Disponible, $lit->fresh()->statut);

        $plan->assertSee('Chambre 101');
    }

    public function test_le_referentiel_d_un_autre_internat_ne_se_lit_ni_ne_se_modifie(): void
    {
        $lits = $this->creerLits($this->filles);
        $batiment = $lits->first()->chambre->batiment;

        // Le gestionnaire des garçons, comme celui d'une autre région, ne touche pas au référentiel des filles.
        foreach ([$this->gestionnaireGarcons, $this->gestionnaireAilleurs] as $compte) {
            Livewire::actingAs($compte)->test(Plan::class)->assertDontSee('Chambre '.$lits->first()->chambre->numero)
                ->call('preparerBatiment', $batiment->id)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->call('preparerLit', $lits->first()->chambre_id)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->call('voirLit', $lits->first()->id)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->set('internat', (string) $this->filles->id)->assertForbidden();
        }

        // Le chef de pôle consulte le plan, sans pouvoir le modifier.
        Livewire::actingAs($this->chefDePole)->test(Plan::class)->set('internat', (string) $this->filles->id)
            ->assertSee('Chambre '.$lits->first()->chambre->numero)->call('preparerBatiment')->assertForbidden();
    }

    public function test_le_plan_se_choisit_par_la_hierarchie_quand_plusieurs_internats_sont_en_charge(): void
    {
        $this->creerLits($this->filles)->first()->chambre->update(['numero' => 'F-101']);
        $this->creerLits($this->garcons)->first()->chambre->update(['numero' => 'G-201']);
        $complexe = $this->etablissement->complexe;

        // Gestionnaire d'un seul internat : rien à choisir, son plan s'affiche comme avant.
        Livewire::actingAs($this->gestionnaireFilles)->test(Plan::class)
            ->assertViewHas('filtre', null)->assertSee('Chambre F-101')->assertDontSee('Région : toutes');

        // Chef de pôle : le premier internat de son périmètre est proposé, avec sa hiérarchie déjà remplie.
        $plan = Livewire::actingAs($this->chefDePole)->test(Plan::class)
            ->assertSet('internat', (string) $this->filles->id)->assertSet('region', (string) $this->region->id)
            ->assertSet('province', (string) $complexe->province_id)->assertSet('complexe', (string) $complexe->id)
            ->assertSet('etablissement', (string) $this->etablissement->id)->assertSee('Chambre F-101')
            ->assertViewHas('filtre', fn (array $f) => $f['regions']->keys()->all() === [$this->region->id]
                && $f['internats']->keys()->sort()->values()->all() === collect([$this->filles->id, $this->garcons->id])->sort()->values()->all());

        // Changer l'établissement efface l'internat : le plan attend un nouveau choix.
        $plan->set('etablissement', '')->assertSet('internat', '')->assertSee('Choisissez un internat')->assertDontSee('Chambre F-101')
            ->set('etablissement', (string) $this->etablissement->id)->set('internat', (string) $this->garcons->id)
            ->assertSee('Chambre G-201')->assertDontSee('Chambre F-101');

        // Changer la région efface tous les niveaux en dessous.
        $plan->set('region', '')->assertSet('province', '')->assertSet('complexe', '')->assertSet('etablissement', '')->assertSet('internat', '');

        // Un internat donné dans l'adresse fixe les niveaux au-dessus de lui ; hors périmètre, il est refusé.
        Livewire::actingAs($this->chefDePole)->withQueryParams(['internat' => (string) $this->garcons->id])->test(Plan::class)
            ->assertSet('etablissement', (string) $this->etablissement->id)->assertSee('Chambre G-201');
        Livewire::actingAs($this->chefDePole)->test(Plan::class)->set('internat', (string) $this->ailleurs->id)->assertForbidden();
    }

    public function test_le_gestionnaire_attribue_un_lit_puis_suit_le_sejour(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $lits = $this->creerLits($this->filles);
        $demande = $this->creerConfirme($campagne);
        $autre = $this->creerLits($this->garcons)->first();

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Affectations::class)->assertSee($demande->numero_dossier)->call('preparer', $demande->id);

        // Un lit d'un autre internat n'existe pas pour ce compte.
        $ecran->set('lit', (string) $autre->id)->call('affecter')->assertNotFound();
        $this->assertSame(0, Affectation::count());

        Livewire::actingAs($this->gestionnaireFilles)->test(Affectations::class)->call('preparer', $demande->id)
            ->set('lit', (string) $lits[0]->id)->set('date_debut', now()->subDay()->toDateString())->call('affecter')->assertHasErrors('date_debut')
            ->set('date_debut', now()->toDateString())->call('affecter')->assertHasNoErrors();

        $affectation = Affectation::sole();
        $this->assertSame($lits[0]->id, $affectation->lit_id);
        Livewire::actingAs($this->gestionnaireFilles)->test(Affectations::class)->assertDontSee($demande->numero_dossier);
        Livewire::actingAs($this->gestionnaireFilles)->test(Residents::class)->assertSee($demande->numero_dossier)->assertSee('Arrivée attendue');

        $fiche = Livewire::actingAs($this->gestionnaireFilles)->test(ResidentFiche::class, ['affectation' => $affectation]);
        $fiche->call('enregistrerArrivee')->assertHasNoErrors()->assertSee('Présent');
        $this->assertTrue($affectation->fresh()->arriveeEnregistree());

        // Remise puis restitution d'un équipement ; une détérioration exige une observation.
        $cle = Equipement::create(['internat_id' => $this->filles->id, 'libelle' => 'Clé de chambre', 'actif' => true]);
        $fiche->call('preparer', 'equipements')->call('remettre')->assertHasErrors('equipements');
        $fiche->set('equipements', [(string) $cle->id])->call('remettre')->assertHasNoErrors();
        $attribution = $affectation->attributions()->sole();
        $fiche->call('preparerRestitution', $attribution->id)->set('etat', EtatRestitution::Deteriore->value)->call('restituer')->assertHasErrors('observation');
        $fiche->set('observation', 'Clé tordue.')->call('restituer')->assertHasNoErrors();
        $this->assertSame(EtatRestitution::Deteriore, $attribution->fresh()->etat_restitution);

        // Fin de séjour : le lit est libéré, la période est conservée.
        $fiche->call('preparer', 'fin')->set('motif', 'non')->call('cloturer')->assertHasErrors('motif');
        $fiche->set('motif', 'Fin de la formation.')->call('cloturer')->assertHasNoErrors();
        $this->assertNotNull($affectation->fresh()->date_fin);
        $this->assertSame(StatutLit::Disponible, $lits[0]->fresh()->statut);
        $this->assertSame(1, Affectation::where('lit_id', $lits[0]->id)->count());
    }

    public function test_un_changement_de_lit_conserve_la_periode_precedente(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $lits = $this->creerLits($this->filles);
        $affectation = $this->creerResident($campagne, $lits[0]);

        Livewire::actingAs($this->gestionnaireFilles)->test(ResidentFiche::class, ['affectation' => $affectation])
            ->call('preparer', 'lit')->set('lit', (string) $lits[1]->id)->set('motif', 'Rapprochement du rez-de-chaussée.')
            ->call('changerDeLit')->assertHasNoErrors()->assertRedirect();

        $this->assertNotNull($affectation->fresh()->date_fin);
        $nouvelle = Affectation::whereNull('date_fin')->sole();
        $this->assertSame($lits[1]->id, $nouvelle->lit_id);
        $this->assertSame(2, Affectation::where('demande_id', $affectation->demande_id)->count());
        $this->assertSame(1, Affectation::whereNull('date_fin')->where('lit_id', $lits[1]->id)->count());
    }

    public function test_l_hebergement_est_cloisonne_par_internat_et_par_role(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $affectation = $this->creerResident($campagne, $this->creerLits($this->filles)->first());

        $this->actingAs($this->gestionnaireGarcons)->get(route('hebergement.residents.fiche', $affectation))->assertForbidden();
        $this->get(route('hebergement.residents.attestation', $affectation))->assertForbidden();
        Livewire::actingAs($this->gestionnaireGarcons)->test(Residents::class)->assertDontSee($affectation->demande->numero_dossier);

        // Le chef de pôle consulte, sans gérer.
        $this->actingAs($this->chefDePole)->get(route('hebergement.residents.fiche', $affectation))->assertOk();
        Livewire::actingAs($this->chefDePole)->test(ResidentFiche::class, ['affectation' => $affectation])->call('preparer', 'fin')->assertForbidden();
        $this->assertNull($affectation->fresh()->date_fin);

        // La restauration n'ouvre pas l'hébergement ; la direction ne gère pas le référentiel.
        $this->actingAs($this->restauration)->get(route('hebergement.residents'))->assertForbidden();
        $this->actingAs($this->directionRegionale)->get(route('hebergement.import'))->assertForbidden();

        $this->actingAs($this->gestionnaireFilles)->get(route('hebergement.residents.attestation', $affectation))->assertOk();
        $this->get(route('hebergement.plan'))->assertOk();
        $this->get(route('hebergement.affectations'))->assertOk();
    }

    public function test_le_gestionnaire_importe_le_referentiel_de_son_internat_en_deux_temps(): void
    {
        $csv = "batiment_code;batiment_nom;nombre_etages;chambre_numero;etage;type_chambre;capacite;lit_numero\n"
            ."A;Bâtiment A;1;101;1;standard;2;1\n"
            ."A;Bâtiment A;1;101;1;standard;2;2\n";

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(ImportReferentiel::class);
        $ecran->call('analyser')->assertHasErrors('fichier');
        $ecran->set('fichier', UploadedFile::fake()->createWithContent('referentiel.csv', $csv))->call('analyser')->assertHasNoErrors()->assertSee('Le fichier est importable');

        // L'analyse n'écrit rien dans le référentiel.
        $import = Import::sole();
        $this->assertSame($this->filles->id, $import->internat_id);
        $this->assertSame(StatutImport::Analyse, $import->statut);
        $this->assertSame(0, Lit::count());

        $ecran->call('confirmer')->assertHasNoErrors()->assertSee('Référentiel importé');
        $this->assertSame(StatutImport::Importe, $import->fresh()->statut);
        $this->assertSame(2, Lit::count());
        $this->assertSame($this->filles->id, Batiment::sole()->internat_id);

        // L'import d'un autre gestionnaire ne se rouvre pas, et le modèle se télécharge.
        Livewire::actingAs($this->gestionnaireGarcons)->test(ImportReferentiel::class)->call('rouvrir', $import->id)->assertForbidden();
        $this->actingAs($this->gestionnaireFilles)->get(route('hebergement.import.modele'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get(route('hebergement.import.rapport', $import))->assertOk();
    }
}
