<?php

namespace Tests\Feature;

use App\Enums\DeclencheurCritere;
use App\Enums\ListeAdmission;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Livewire\Personnel\Classement;
use App\Livewire\Personnel\ListeAttente;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use App\Models\NoteManuelle;
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

    public function test_le_classement_propose_les_listes_et_le_directeur_valide_les_admissions(): void
    {
        [$campagne, [$premier, $deuxieme, $troisieme]] = $this->campagneAClasser();

        // La délibération est réservée au directeur du complexe / CMC.
        $ecran = Livewire::actingAs($this->directeur)->test(Classement::class, ['campagne' => (string) $campagne->id])
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
        $this->assertSame($this->directeur->id, DecisionAdmission::where('demande_id', $premier->id)->sole()->valide_par);
    }

    public function test_le_directeur_joint_un_message_general_affiche_dans_le_compte_de_chaque_candidat(): void
    {
        [$campagne, [$premier, , $troisieme]] = $this->campagneAClasser();
        $message = 'Bravo aux admis et merci a tous les candidats pour leur participation.';

        Livewire::actingAs($this->directeur)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->set('messageDeliberation', $message)
            ->call('deliberer')
            ->assertHasNoErrors();

        // Le message, unique, est conservé sur la campagne.
        $this->assertTrue($campagne->fresh()->estDeliberee());
        $this->assertSame($message, $campagne->fresh()->message_deliberation);

        // Il s'affiche à l'identique dans le compte de chaque candidat, quel que soit son sort.
        $this->ecran($premier->stagiaire, \App\Livewire\Stagiaire\Dossier::class)
            ->assertSee('Message de l')->assertSee($message);
        $this->ecran($troisieme->stagiaire, \App\Livewire\Stagiaire\Dossier::class)
            ->assertSee($message);
    }

    public function test_une_egalite_a_la_limite_bloque_la_validation_sans_confirmation_expresse(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 1]);
        $a = $this->rendreComplet($this->creerDossierRecu($campagne));
        $b = $this->rendreComplet($this->creerDossierRecu($campagne));
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee, 'date_cloture' => now()->subDay()->toDateString()])->save();

        $ecran = Livewire::actingAs($this->directeur)->test(Classement::class)->set('campagne', (string) $campagne->id)->call('recalculer')
            ->assertSee('Égalité à la limite des places');

        $ecran->call('deliberer');
        $this->assertFalse($campagne->fresh()->estDeliberee());

        $ecran->set('arbitrage', true)->call('deliberer');
        $this->assertTrue($campagne->fresh()->estDeliberee());
        $this->assertSame(1, Demande::whereKey([$a->id, $b->id])->where('statut', StatutDemande::Admis->value)->count());
    }

    public function test_seul_le_directeur_delibere_les_autres_consultent_ou_sont_exclus(): void
    {
        [$campagne] = $this->campagneAClasser();

        // Le directeur délibère : il voit le classement et le bouton de validation.
        Livewire::actingAs($this->directeur)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->assertSee('Valider les admissions');

        // Le chef de pôle consulte le classement mais ne délibère plus : pas de bouton de validation.
        Livewire::actingAs($this->chefDePole)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->assertSee($campagne->internat->nom)
            ->assertDontSee('Valider les admissions');

        // La direction régionale n'a aucune permission d'admissions : accès refusé.
        Livewire::actingAs($this->directionRegionale)->test(Classement::class)->assertForbidden();

        // Le gestionnaire ouvre la liste d'attente (c'est lui qui appelle les candidats).
        Livewire::actingAs($this->gestionnaireFilles)->test(ListeAttente::class)->assertSee('attente');

        $this->assertFalse($campagne->fresh()->estDeliberee());
    }

    public function test_le_chef_de_pole_consulte_et_imprime_la_liste_des_candidats(): void
    {
        [$campagne, [$premier]] = $this->campagneAClasser();

        // Le chef de pôle (consultation seule) ouvre la liste imprimable avec tout ce qu'il faut pour délibérer.
        $this->connecter($this->chefDePole)->get(route('admissions.classement.impression', $campagne))
            ->assertOk()
            ->assertSee('Liste des candidats')
            ->assertSee('Imprimer')
            ->assertSee($premier->stagiaire->cef)
            ->assertSee($premier->stagiaire->nom_filiere)
            ->assertSee($campagne->internat->etablissement->nom_efp)
            ->assertSee($premier->numero_dossier)
            ->assertSee($premier->score.' / '.config('internat.score.bareme_maximum'));

        // Sans la permission de consultation des admissions (gestionnaire), la liste est refusée.
        $this->connecter($this->gestionnaireFilles)->get(route('admissions.classement.impression', $campagne))->assertForbidden();
    }

    public function test_le_chef_de_pole_rectifie_une_note_apres_cloture_et_le_classement_est_recalcule(): void
    {
        [$campagne, [$premier, $deuxieme, $troisieme]] = $this->campagneAClasser();
        $eloigne = $campagne->criteres->firstWhere('declencheur', DeclencheurCritere::EloigneOuRural);

        // Départ : premier 35, deuxième 25 (admis), troisième 10 (liste d'attente).
        $this->assertSame(ListeAdmission::Attente, $troisieme->fresh()->liste);
        $this->assertSame(10, $troisieme->fresh()->score);

        // Le chef de pôle ouvre le détail du score puis rectifie à la main la note « éloigné » du troisième.
        $ecran = Livewire::actingAs($this->chefDePole)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->call('voirScore', $troisieme->id)
            ->assertSet('dialogue', 'score');

        // Une note au-delà du maximum du critère est refusée.
        $ecran->set("notes.{$eloigne->id}", 99)->call('enregistrerNote', $eloigne->id)->assertHasErrors("notes.{$eloigne->id}");

        // Une note valide est enregistrée : score recalculé, classement réordonné, override persisté.
        $ecran->set("notes.{$eloigne->id}", 20)->call('enregistrerNote', $eloigne->id)->assertHasNoErrors();

        $this->assertSame(30, $troisieme->fresh()->score);
        $this->assertSame(ListeAdmission::Principale, $troisieme->fresh()->liste);
        $this->assertSame(2, $troisieme->fresh()->rang);
        // Le troisième dépasse l'ancien deuxième (25), qui bascule en liste d'attente.
        $this->assertSame(ListeAdmission::Attente, $deuxieme->fresh()->liste);
        $this->assertSame(ListeAdmission::Principale, $premier->fresh()->liste);

        $note = NoteManuelle::where('demande_id', $troisieme->id)->where('critere_id', $eloigne->id)->sole();
        $this->assertSame(20, $note->points);
        $this->assertSame($this->chefDePole->id, $note->ajuste_par);

        // Rétablir la note automatique (0 pour un critère non déclaré) ramène le troisième en liste d'attente.
        $ecran->call('retablirNote', $eloigne->id)->assertHasNoErrors();
        $this->assertSame(10, $troisieme->fresh()->score);
        $this->assertSame(ListeAdmission::Attente, $troisieme->fresh()->liste);
        $this->assertDatabaseMissing('demande_note_manuelle', ['demande_id' => $troisieme->id, 'critere_id' => $eloigne->id]);
    }

    public function test_la_rectification_des_notes_est_fermee_aux_autres_roles_et_hors_cloture(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 2]);
        $dossier = $this->rendreComplet($this->creerDossierRecu($campagne, ['eloigne_ou_rural' => true]));
        $eloigne = $campagne->criteres->firstWhere('declencheur', DeclencheurCritere::EloigneOuRural);

        // Campagne encore ouverte : même le chef de pôle ne peut pas rectifier (règle métier refusée).
        app(\App\Domain\Admissions\Classement::class)->recalculer($campagne);
        Livewire::actingAs($this->chefDePole)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->call('voirScore', $dossier->id)
            ->set("notes.{$eloigne->id}", 5)
            ->call('enregistrerNote', $eloigne->id)
            ->assertDispatched('message', ton: 'danger');
        $this->assertDatabaseCount('demande_note_manuelle', 0);

        // Après clôture, le gestionnaire n'a pas la permission : l'action est interdite.
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee, 'date_cloture' => now()->subDay()->toDateString()])->save();
        Livewire::actingAs($this->gestionnaireFilles)->test(Classement::class, ['campagne' => (string) $campagne->id])
            ->assertForbidden();
    }

    public function test_le_gestionnaire_appelle_un_candidat_de_la_liste_d_attente_et_motive_un_choix_hors_ordre(): void
    {
        [$campagne, [$premier, $deuxieme, $troisieme]] = $this->campagneAClasser();
        $quatrieme = Demande::factory()->deposee(StatutDemande::Complet)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id, 'score' => 0,
        ]);
        $this->deliberer($campagne);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // C'est le gestionnaire de l'internat qui appelle depuis la liste d'attente.
        // Aucune place libre : l'appel est refusé.
        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(ListeAttente::class)->assertSee($troisieme->numero_dossier);
        $ecran->call('promouvoir', $troisieme->id);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // Un admis se désiste : une place se libère, sans promotion automatique.
        Demande::whereKey($premier->id)->update(['statut' => StatutDemande::Desiste->value]);
        $this->assertSame(StatutDemande::EnAttente, $troisieme->fresh()->statut);

        // Appeler un candidat moins bien classé exige un motif.
        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(ListeAttente::class);
        $ecran->call('promouvoir', $quatrieme->id);
        $this->assertSame(StatutDemande::EnAttente, $quatrieme->fresh()->statut);

        $ecran->call('promouvoir', $troisieme->id);
        $this->assertSame(StatutDemande::Admis, $troisieme->fresh()->statut);
        $this->assertSame(1, Campagne::find($campagne->id)->demandes()->where('statut', StatutDemande::EnAttente->value)->count());
    }
}
