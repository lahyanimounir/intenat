<?php

namespace Tests\Feature;

use App\Enums\ListeAdmission;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Livewire\Personnel\Classement;
use App\Livewire\Personnel\ListeAttente;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Classement, délibération et liste d'attente dans la nouvelle interface. Le
 * chef de pôle scolarité reste seul à valider les admissions et à appeler un
 * candidat en attente ; rien n'est jamais promu automatiquement.
 */
class EcransAdmissionsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    /** Campagne clôturée de deux places, avec trois dossiers complets aux scores 35, 25 et 10. */
    private function campagneAClasser(): array
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 2]);
        $dossiers = [
            $this->rendreComplet($this->creerDossierRecu($campagne, ['eloigne_ou_rural' => true])),
            $this->rendreComplet($this->creerDossierRecu($campagne, ['eloigne_ou_rural' => true], $this->creerStagiaire($this->filles, ['niveau_formation' => 'technicien']))),
            $this->rendreComplet($this->creerDossierRecu($campagne)),
        ];
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee, 'date_cloture' => now()->subDay()->toDateString()])->save();

        return [$campagne->fresh(), $dossiers];
    }

    public function test_le_classement_propose_les_listes_et_le_chef_de_pole_valide_les_admissions(): void
    {
        [$campagne, [$premier, $deuxieme, $troisieme]] = $this->campagneAClasser();

        $ecran = Livewire::actingAs($this->chefDePole)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->set('campagne', (string) $campagne->id)
            ->call('recalculer')
            ->assertSee($premier->numero_dossier)
            ->assertSee('Proposition');

        $this->assertSame(ListeAdmission::Principale, $premier->fresh()->liste);
        $this->assertSame(ListeAdmission::Attente, $troisieme->fresh()->liste);

        // Le détail du score est consultable ; un écart à la proposition exige un motif.
        $ecran->call('voirScore', $premier->id)->assertSee('Détail du score')->assertSee('Milieu rural ou éloigné')->call('fermerDialogue');
        $ecran->call('preparerAjustement', $deuxieme->id)->set('decision', 'refuse')->set('motif', 'non')->call('enregistrerAjustement')->assertHasErrors('motif');
        $ecran->set('motif', 'Fausse déclaration constatée à la délibération.')->call('enregistrerAjustement')->assertHasNoErrors()->assertSee('Ajusté : Refusé');

        $ecran->call('deliberer')->assertHasNoErrors();

        $this->assertTrue($campagne->fresh()->estDeliberee());
        $this->assertSame(StatutDemande::Admis, $premier->fresh()->statut);
        $this->assertSame(StatutDemande::Refuse, $deuxieme->fresh()->statut);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);
        $this->assertSame('Fausse déclaration constatée à la délibération.', DecisionAdmission::where('demande_id', $deuxieme->id)->sole()->motif);
        $this->assertSame($this->chefDePole->id, DecisionAdmission::where('demande_id', $premier->id)->sole()->valide_par);
    }

    public function test_une_egalite_a_la_limite_bloque_la_validation_sans_confirmation_expresse(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 1]);
        $a = $this->rendreComplet($this->creerDossierRecu($campagne));
        $b = $this->rendreComplet($this->creerDossierRecu($campagne));
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee, 'date_cloture' => now()->subDay()->toDateString()])->save();

        $ecran = Livewire::actingAs($this->chefDePole)->test(Classement::class)->set('campagne', (string) $campagne->id)->call('recalculer')
            ->assertSee('Égalité à la limite des places');

        $ecran->call('deliberer');
        $this->assertFalse($campagne->fresh()->estDeliberee());

        $ecran->set('arbitrage', true)->call('deliberer');
        $this->assertTrue($campagne->fresh()->estDeliberee());
        $this->assertSame(1, Demande::whereKey([$a->id, $b->id])->where('statut', StatutDemande::Admis->value)->count());
    }

    public function test_seul_le_chef_de_pole_de_l_etablissement_delibere(): void
    {
        [$campagne] = $this->campagneAClasser();

        // Le gestionnaire n'ouvre ni le classement ni la liste d'attente.
        $this->actingAs($this->gestionnaireFilles)->get(route('admissions.classement'))->assertForbidden();
        $this->actingAs($this->gestionnaireFilles)->get(route('admissions.liste-attente'))->assertForbidden();

        // La direction régionale n'a pas la permission d'admissions.
        $this->actingAs($this->directionRegionale)->get(route('admissions.classement'))->assertForbidden();

        $this->actingAs($this->chefDePole)->get(route('admissions.classement', ['campagne' => $campagne->id]))->assertOk()->assertSee('Valider les admissions');
        $this->assertFalse($campagne->fresh()->estDeliberee());
    }

    public function test_le_chef_de_pole_appelle_un_candidat_de_la_liste_d_attente_et_motive_un_choix_hors_ordre(): void
    {
        [$campagne, [$premier, $deuxieme, $troisieme]] = $this->campagneAClasser();
        $quatrieme = Demande::factory()->deposee(StatutDemande::Complet)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id, 'score' => 0,
        ]);
        $this->deliberer($campagne);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // Aucune place libre : l'appel est refusé.
        $ecran = Livewire::actingAs($this->chefDePole)->test(ListeAttente::class)->assertSee($troisieme->numero_dossier);
        $ecran->call('promouvoir', $troisieme->id);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // Un admis se désiste : une place se libère, sans promotion automatique.
        Demande::whereKey($premier->id)->update(['statut' => StatutDemande::Desiste->value]);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // Appeler un candidat moins bien classé exige un motif.
        $ecran = Livewire::actingAs($this->chefDePole)->test(ListeAttente::class);
        $ecran->call('promouvoir', $quatrieme->id);
        $this->assertSame(StatutDemande::EnAttente, $quatrieme->fresh()->statut);

        $ecran->call('promouvoir', $troisieme->id);
        $troisieme->refresh();
        $this->assertSame(StatutDemande::Admis, $troisieme->statut);
        $this->assertNotNull($troisieme->date_limite_confirmation);
        $this->assertTrue($troisieme->date_limite_confirmation->between(now()->addHours(47), now()->addHours(49)));
        $this->assertSame(1, Campagne::find($campagne->id)->demandes()->where('statut', StatutDemande::EnAttente->value)->count());
    }
}
