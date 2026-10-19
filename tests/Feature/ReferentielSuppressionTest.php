<?php

namespace Tests\Feature;

use App\Enums\StatutChambre;
use App\Livewire\Personnel\Plan;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\DemandeMaintenance;
use App\Models\JournalAudit;
use App\Models\Lit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Plan d'occupation : le gestionnaire ajoute, modifie et supprime bâtiments, étages, chambres et
 * lits de son seul internat. Une structure qui porte un historique (lit déjà attribué, chambre
 * avec maintenance) ne se supprime pas : l'historique est conservé.
 */
class ReferentielSuppressionTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private function plan()
    {
        return Livewire::actingAs($this->gestionnaireFilles)->test(Plan::class);
    }

    public function test_le_gestionnaire_gere_etages_chambres_et_lits_de_son_internat(): void
    {
        $batiment = Batiment::factory()->create(['internat_id' => $this->filles->id, 'nombre_etages' => 1]);
        $rdc = Chambre::factory()->create(['batiment_id' => $batiment->id, 'etage' => 0, 'numero' => 'R-01', 'capacite' => 2]);
        $haut = Chambre::factory()->create(['batiment_id' => $batiment->id, 'etage' => 1, 'numero' => 'H-11', 'capacite' => 2]);
        $lit = Lit::factory()->create(['chambre_id' => $rdc->id, 'numero' => '1']);
        Lit::factory()->create(['chambre_id' => $haut->id, 'numero' => '1']);

        // Étage : ajout au sommet, chambre ajoutée directement sur cet étage, renumérotation.
        $plan = $this->plan()->call('ajouterEtage', $batiment->id)->assertHasNoErrors();
        $this->assertSame(2, $batiment->fresh()->nombre_etages);
        $plan->call('preparerChambre', $batiment->id, null, 2)->assertSet('chambre.etage', 2)
            ->set('chambre.numero', 'S-21')->call('enregistrerChambre')->assertHasNoErrors();
        $plan->call('preparerEtage', $batiment->id, 1)->set('nouvelEtage', '2')->call('renumeroterEtage')->assertDispatched('message', ton: 'danger');
        $plan->call('preparerEtage', $batiment->id, 2)->set('nouvelEtage', '0')->call('renumeroterEtage')->assertDispatched('message', ton: 'danger');
        Chambre::where('numero', 'S-21')->delete();
        $plan->call('preparerEtage', $batiment->id, 1)->set('nouvelEtage', '2')->call('renumeroterEtage')->assertHasNoErrors();
        $this->assertSame(2, $haut->fresh()->etage);

        // Lit : renommé, puis supprimé (jamais attribué).
        $plan->call('voirLit', $lit->id)->assertSet('numeroLit', '1')->set('numeroLit', '1A')->call('renommerLit')->assertHasNoErrors();
        $this->assertSame('1A', $lit->fresh()->numero);
        $plan->call('supprimerLit')->assertHasNoErrors();
        $this->assertNull(Lit::find($lit->id));

        // Chambre et étage du sommet : supprimés avec leurs lits, l'étage disparaît du bâtiment.
        $plan->call('supprimerChambre', $rdc->id)->assertHasNoErrors();
        $this->assertNull(Chambre::find($rdc->id));
        $plan->call('supprimerEtage', $batiment->id, 2)->assertHasNoErrors();
        $this->assertSame([null, 1], [Chambre::find($haut->id), $batiment->fresh()->nombre_etages]);

        // Bâtiment : supprimé avec ce qu'il contient ; chaque suppression est tracée.
        $plan->call('supprimerBatiment', $batiment->id)->assertHasNoErrors();
        $this->assertNull(Batiment::find($batiment->id));
        $this->assertSame(5, JournalAudit::whereIn('action', ['lit.suppression', 'chambre.suppression', 'batiment.etage_supprime', 'batiment.suppression'])->count());
    }

    public function test_une_structure_avec_historique_ne_se_supprime_pas(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $lits = $this->creerLits($this->filles);
        $occupe = $lits[0];
        $this->creerResident($campagne, $occupe);
        $chambreOccupee = $occupe->chambre;
        $batiment = $chambreOccupee->batiment;

        // Un lit déjà attribué, sa chambre, son étage et son bâtiment restent : l'historique d'occupation est conservé.
        $this->plan()->call('voirLit', $occupe->id)->call('supprimerLit')->assertDispatched('message', ton: 'danger');
        $this->plan()->call('supprimerChambre', $chambreOccupee->id)->assertDispatched('message', ton: 'danger');
        $this->plan()->call('supprimerEtage', $batiment->id, $chambreOccupee->etage)->assertDispatched('message', ton: 'danger');
        $this->plan()->call('supprimerBatiment', $batiment->id)->assertDispatched('message', ton: 'danger');
        $this->assertNotNull(Lit::find($occupe->id));
        $this->assertNotNull(Batiment::find($batiment->id));

        // Une chambre avec un historique de maintenance non plus.
        $autre = $lits->first(fn (Lit $l) => $l->chambre_id !== $chambreOccupee->id)->chambre;
        DemandeMaintenance::create([
            'chambre_id' => $autre->id, 'description' => 'Fuite', 'statut' => 'resolue', 'date_demande' => now(),
            'saisi_par' => $this->gestionnaireFilles->id, 'bloque_chambre' => false,
        ]);
        $this->plan()->call('supprimerChambre', $autre->id)->assertDispatched('message', ton: 'danger');
        $this->assertSame(StatutChambre::Disponible, $autre->fresh()->statut);
    }

    public function test_les_structures_d_un_autre_internat_ne_se_suppriment_pas(): void
    {
        $lits = $this->creerLits($this->filles);
        $chambre = $lits[0]->chambre;
        $batiment = $chambre->batiment;

        foreach ([$this->gestionnaireGarcons, $this->chefDePole] as $compte) {
            Livewire::actingAs($compte)->test(Plan::class)->call('supprimerBatiment', $batiment->id)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->call('supprimerChambre', $chambre->id)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->call('supprimerEtage', $batiment->id, 0)->assertForbidden();
            Livewire::actingAs($compte)->test(Plan::class)->call('ajouterEtage', $batiment->id)->assertForbidden();
        }

        $this->assertNotNull(Batiment::find($batiment->id));
        $this->assertSame(2, Chambre::where('batiment_id', $batiment->id)->count());
    }
}
