<?php

namespace Tests\Feature;

use App\Enums\DeclencheurCritere;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutModificationCritere;
use App\Enums\StatutPiece;
use App\Livewire\Personnel\Approbations;
use App\Livewire\Personnel\CampagneFiche;
use App\Livewire\Personnel\Campagnes;
use App\Livewire\Personnel\DossierFiche;
use App\Livewire\Personnel\Dossiers;
use App\Livewire\Personnel\GrilleReference;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\Demande;
use App\Models\JournalAudit;
use App\Models\ModificationCritere;
use App\Models\TypePiece;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Écrans du personnel pour les campagnes et l'instruction des dossiers, dans la
 * nouvelle interface : mêmes règles, mêmes périmètres et mêmes autorisations
 * que l'ancienne API, désormais exercés à travers les composants Livewire.
 */
class EcransCampagnesEtDossiersTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    /* ------------------------------------------------------------ Campagnes */

    public function test_le_chef_de_pole_cree_une_campagne_par_internat_et_la_grille_de_reference_y_est_recopiee(): void
    {
        $this->creerLits($this->filles, 3, 2);
        CritereReference::create(['categorie' => 'social', 'declencheur' => 'demuni', 'valeur_attendue' => '', 'condition_texte' => 'Stagiaire démuni', 'points' => 20, 'actif' => true]);

        $ecran = Livewire::actingAs($this->chefDePole)->test(Campagnes::class)->call('preparer');

        $ecran->set('nouvelle.annee', '2026-2028')->set('nouvelle.date_cloture', now()->addMonth()->toDateString())->set("places.{$this->filles->id}", 4)
            ->call('creer')->assertHasErrors('nouvelle.annee');
        $ecran->set('nouvelle.annee', '2026-2027')->set("places.{$this->filles->id}", '')->call('creer')->assertHasErrors('places');

        $ecran->set("places.{$this->filles->id}", 4)->set('nouvelle.frais_mensuels', '300')->call('creer')->assertHasNoErrors();

        $campagne = Campagne::sole();
        $this->assertSame($this->filles->id, $campagne->internat_id);
        $this->assertSame(StatutCampagne::Preparee, $campagne->statut);
        $this->assertSame(4, $campagne->nombre_places);
        $this->assertSame($this->chefDePole->id, $campagne->cree_par);
        $this->assertSame(1, $campagne->criteres()->count());

        // Une seconde campagne la même année pour le même internat est refusée.
        Livewire::actingAs($this->chefDePole)->test(Campagnes::class)->call('preparer')
            ->set('nouvelle.annee', '2026-2027')->set('nouvelle.date_cloture', now()->addMonth()->toDateString())->set("places.{$this->filles->id}", 2)->call('creer');
        $this->assertSame(1, Campagne::count());
    }

    public function test_seul_le_chef_de_pole_de_l_etablissement_cree_ou_modifie_une_campagne(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee]);

        // Le gestionnaire consulte, sans pouvoir gérer.
        Livewire::actingAs($this->gestionnaireFilles)->test(Campagnes::class)->call('preparer')->assertForbidden();
        Livewire::actingAs($this->gestionnaireFilles)->test(CampagneFiche::class, ['campagne' => $campagne])->call('transiter', 'ouvrir')->assertForbidden();
        Livewire::actingAs($this->gestionnaireFilles)->test(CampagneFiche::class, ['campagne' => $campagne])->call('supprimer')->assertForbidden();

        // Hors périmètre : la fiche est refusée.
        $this->actingAs($this->gestionnaireAilleurs)->get(route('campagnes.fiche', $campagne))->assertForbidden();
        Livewire::actingAs($this->gestionnaireAilleurs)->test(Campagnes::class)->assertDontSee($this->filles->nom);

        // Un chef de pôle ne crée pas de campagne pour un internat d'un autre établissement.
        Livewire::actingAs($this->chefDePole)->test(Campagnes::class)->call('preparer')
            ->set('nouvelle.annee', '2027-2028')->set('nouvelle.date_cloture', now()->addMonth()->toDateString())->set("places.{$this->ailleurs->id}", 3)->call('creer');
        $this->assertSame(0, Campagne::where('internat_id', $this->ailleurs->id)->count());

        $this->assertSame(StatutCampagne::Preparee, $campagne->fresh()->statut);
    }

    public function test_une_campagne_ne_s_ouvre_que_si_elle_est_complete_puis_se_cloture(): void
    {
        $this->creerLits($this->filles, 2, 2);
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee, 'nombre_places' => 10, 'frais_mensuels' => 300]);

        $fiche = Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])
            ->assertSee('La campagne ne peut pas encore être ouverte')
            ->call('transiter', 'ouvrir');
        $this->assertSame(StatutCampagne::Preparee, $campagne->fresh()->statut);

        $fiche->call('preparerModification')->set('parametres.nombre_places', 4)->call('modifier')->assertHasNoErrors();
        $fiche->call('transiter', 'ouvrir');
        $this->assertSame(StatutCampagne::Ouverte, $campagne->fresh()->statut);

        $fiche->call('transiter', 'cloturer');
        $this->assertSame(StatutCampagne::Cloturee, $campagne->fresh()->statut);
    }

    public function test_pieces_et_criteres_ne_se_modifient_qu_en_preparation(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee]);
        $fiche = Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne]);

        $fiche->call('preparerPiece')->set('piece.libelle', 'Copie de la CIN')->call('enregistrerPiece')->assertHasErrors('piece.libelle');
        $fiche->set('piece.libelle', 'Certificat de résidence')->set('piece.obligatoire', false)->call('enregistrerPiece')->assertHasNoErrors();
        $piece = TypePiece::where('libelle', 'Certificat de résidence')->sole();
        $this->assertTrue(JournalAudit::where('action', 'type_piece.creation')->where('entite_id', $piece->id)->exists());

        // Les critères relèvent de l'administrateur : le chef de pôle ne les saisit pas.
        Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])->call('preparerCritere')->assertForbidden();

        $admin = Livewire::actingAs($this->admin)->test(CampagneFiche::class, ['campagne' => $campagne]);
        $admin->call('preparerCritere')
            ->set('critere', ['categorie' => 'formation', 'declencheur' => DeclencheurCritere::NiveauFormation->value, 'valeur_attendue' => '', 'condition' => 'Niveau Technicien', 'points' => 5, 'type_piece_id' => ''])
            ->call('enregistrerCritere')->assertHasErrors('critere.valeur_attendue');
        $admin->set('critere.valeur_attendue', 'technicien')->set('critere.type_piece_id', $piece->id)->call('enregistrerCritere')->assertHasNoErrors();
        $this->assertSame($piece->id, Critere::where('condition_texte', 'Niveau Technicien')->sole()->type_piece_id);

        // Une même donnée du dossier ne rapporte des points qu'une fois ; une pièce rattachée à un critère ne se supprime pas.
        $admin->call('preparerCritere')
            ->set('critere', ['categorie' => 'formation', 'declencheur' => DeclencheurCritere::NiveauFormation->value, 'valeur_attendue' => 'technicien', 'condition' => 'Doublon', 'points' => 5, 'type_piece_id' => ''])
            ->call('enregistrerCritere');
        $this->assertFalse(Critere::where('condition_texte', 'Doublon')->exists());
        Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])->call('supprimerPiece', $piece->id);
        $this->assertTrue($piece->fresh()->exists);

        // Une fois la campagne ouverte, plus rien ne se saisit directement.
        $campagne->forceFill(['statut' => StatutCampagne::Ouverte])->save();
        Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])
            ->call('preparerPiece')->set('piece.libelle', 'Pièce tardive')->call('enregistrerPiece');
        $this->assertFalse(TypePiece::where('libelle', 'Pièce tardive')->exists());
        Livewire::actingAs($this->admin)->test(CampagneFiche::class, ['campagne' => $campagne])->call('supprimerCritere', Critere::where('condition_texte', 'Niveau Technicien')->sole()->id);
        $this->assertTrue(Critere::where('condition_texte', 'Niveau Technicien')->exists());
    }

    public function test_la_correction_d_un_critere_suit_la_chaine_d_approbation(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $critere = $campagne->criteres()->where('declencheur', DeclencheurCritere::EloigneOuRural->value)->sole();

        $fiche = Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])->call('preparerCorrection', $critere->id);
        $fiche->set('correction.nouvelle_valeur', '120')->set('correction.motif', 'Erreur de saisie du barème régional.')->call('proposerCorrection')->assertHasErrors('correction.nouvelle_valeur');
        $fiche->set('correction.nouvelle_valeur', '30')->call('proposerCorrection')->assertHasNoErrors();

        $modification = ModificationCritere::sole();
        $this->assertSame(StatutModificationCritere::EnAttenteCmc, $modification->statut);
        $this->assertSame(25, $critere->fresh()->points);

        // La direction régionale ne décide pas avant le directeur de complexe.
        Livewire::actingAs($this->directionRegionale)->test(Approbations::class)->assertDontSee('Milieu rural ou éloigné')->call('approuver', $modification->id)->assertForbidden();

        Livewire::actingAs($this->directeur)->test(Approbations::class)->assertSee('Milieu rural ou éloigné')->call('approuver', $modification->id)->assertHasNoErrors();
        $this->assertSame(StatutModificationCritere::ApprouveCmc, $modification->fresh()->statut);
        $this->assertSame(25, $critere->fresh()->points);

        Livewire::actingAs($this->directionRegionale)->test(Approbations::class)->call('approuver', $modification->id)->assertHasNoErrors();
        $this->assertSame(StatutModificationCritere::ApprouveRegional, $modification->fresh()->statut);
        $this->assertSame(30, $critere->fresh()->points);

        // Le chef de pôle ne peut pas approuver sa propre correction.
        $this->actingAs($this->chefDePole)->get(route('approbations'))->assertForbidden();
    }

    public function test_un_rejet_de_correction_exige_un_motif(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $critere = $campagne->criteres()->first();
        Livewire::actingAs($this->chefDePole)->test(CampagneFiche::class, ['campagne' => $campagne])->call('preparerCorrection', $critere->id)
            ->set('correction.nouvelle_valeur', '15')->set('correction.motif', 'Erreur de saisie du barème.')->call('proposerCorrection');
        $modification = ModificationCritere::sole();

        $ecran = Livewire::actingAs($this->directeur)->test(Approbations::class);
        $ecran->call('rejeter', $modification->id, '');
        $this->assertSame(StatutModificationCritere::EnAttenteCmc, $modification->fresh()->statut);

        $ecran->call('rejeter', $modification->id, 'Le barème régional est correct.');
        $this->assertSame(StatutModificationCritere::Rejete, $modification->fresh()->statut);
    }

    public function test_la_direction_centrale_tient_la_grille_de_reference(): void
    {
        $ecran = Livewire::actingAs($this->directionCentrale)->test(GrilleReference::class)->call('preparer');
        $ecran->set('critere', ['categorie' => 'social', 'declencheur' => 'demuni', 'valeur_attendue' => '', 'condition' => 'Stagiaire démuni', 'points' => 200, 'piece_libelle' => '', 'actif' => true])
            ->call('enregistrer')->assertHasErrors('critere.points');
        $ecran->set('critere.points', 20)->call('enregistrer')->assertHasNoErrors();

        $reference = CritereReference::sole();
        $this->assertSame(20, $reference->points);

        $ecran->call('supprimer', $reference->id);
        $this->assertSame(0, CritereReference::count());

        $this->actingAs($this->admin)->get(route('grille-reference'))->assertForbidden();
        $this->actingAs($this->directionCentrale)->get(route('grille-reference'))->assertOk();
    }

    /* -------------------------------------------------------------- Dossiers */

    public function test_le_gestionnaire_ne_voit_que_les_dossiers_deposes_de_son_internat(): void
    {
        $filles = $this->creerDossierRecu($this->creerCampagne($this->filles));
        $garcons = $this->creerDossierRecu($this->creerCampagne($this->garcons));
        $brouillon = Demande::factory()->create(['campagne_id' => $filles->campagne_id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id]);

        Livewire::actingAs($this->gestionnaireFilles)->test(Dossiers::class)
            ->assertSee($filles->numero_dossier)->assertDontSee($garcons->numero_dossier)
            ->set('recherche', $filles->stagiaire->cef)->assertSee($filles->numero_dossier)
            ->set('recherche', 'introuvable')->assertDontSee($filles->numero_dossier)->assertSee('Aucun dossier ne correspond aux filtres');

        $this->actingAs($this->gestionnaireFilles)->get(route('dossiers.fiche', $filles))->assertOk()->assertSee($filles->stagiaire->cef);
        $this->get(route('dossiers.fiche', $garcons))->assertForbidden();
        // Un brouillon reste privé au stagiaire.
        $this->get(route('dossiers.fiche', $brouillon))->assertNotFound();
        $this->get(route('dossiers.piece', $garcons->pieces->first()))->assertForbidden();
        $this->get(route('dossiers.piece', $filles->pieces->first()))->assertOk();
        $this->assertTrue(JournalAudit::where('action', 'piece.consultation')->exists());

        // Le chef de pôle voit les deux internats de son établissement, sans pouvoir instruire.
        Livewire::actingAs($this->chefDePole)->test(Dossiers::class)->assertSee($filles->numero_dossier)->assertSee($garcons->numero_dossier);
        Livewire::actingAs($this->chefDePole)->test(DossierFiche::class, ['demande' => $filles])->call('declarerComplet')->assertForbidden();
        $this->assertSame(StatutDemande::Recu, $filles->fresh()->statut);
    }

    public function test_le_gestionnaire_instruit_un_dossier_depuis_la_fiche(): void
    {
        $demande = $this->creerDossierRecu($this->creerCampagne($this->filles), ['demuni' => true]);
        [$cin, $sociale] = [$demande->pieces[0], $demande->pieces[1]];
        $fiche = Livewire::actingAs($this->gestionnaireFilles)->test(DossierFiche::class, ['demande' => $demande]);

        // Tant qu'une pièce n'est pas contrôlée, le dossier ne peut pas être déclaré complet.
        $fiche->call('accepterPiece', $cin->id)->call('declarerComplet');
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);

        // Une pièce à remplacer exige un commentaire, puis le dossier se renvoie avec un motif.
        $fiche->call('demanderRemplacement', $sociale->id, '');
        $this->assertSame(StatutPiece::Deposee, $sociale->fresh()->statut);
        $fiche->call('demanderRemplacement', $sociale->id, 'Attestation illisible.');
        $this->assertSame(StatutPiece::ARemplacer, $sociale->fresh()->statut);

        $fiche->call('renvoyer', 'court');
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);
        $fiche->call('renvoyer', 'Merci de fournir une attestation lisible et datée.');
        $this->assertSame(StatutDemande::RetourRectification, $demande->fresh()->statut);

        // Le gestionnaire d'un autre internat n'instruit pas ce dossier.
        Livewire::actingAs($this->gestionnaireGarcons)->test(DossierFiche::class, ['demande' => $demande])->assertForbidden();

        Livewire::actingAs($this->gestionnaireFilles)->test(DossierFiche::class, ['demande' => $demande])->call('rejeter', 'Dossier non conforme après deux relances.');
        $this->assertSame(StatutDemande::Rejete, $demande->fresh()->statut);
        $this->assertSame('Dossier non conforme après deux relances.', $demande->fresh()->motif_rejet);
    }

    public function test_un_dossier_complet_est_note_et_le_score_reste_masque_au_gestionnaire(): void
    {
        $demande = $this->creerDossierRecu($this->creerCampagne($this->filles), ['eloigne_ou_rural' => true]);
        $fiche = Livewire::actingAs($this->gestionnaireFilles)->test(DossierFiche::class, ['demande' => $demande]);

        $fiche->call('accepterPiece', $demande->pieces->first()->id)->call('declarerComplet')->assertHasNoErrors();

        $demande->refresh();
        $this->assertSame(StatutDemande::Complet, $demande->statut);
        $this->assertSame(35, $demande->score);

        // Par défaut, ni le score ni le rang ne sont montrés au gestionnaire ; le chef de pôle les voit.
        $this->actingAs($this->gestionnaireFilles)->get(route('dossiers.fiche', $demande))->assertOk()->assertDontSee('Détail du score');
        $this->actingAs($this->chefDePole)->get(route('dossiers.fiche', $demande))->assertOk()->assertSee('Détail du score')->assertSee('35 / 80');
    }

    public function test_l_occupation_reste_bloquee_tant_que_la_place_n_est_pas_confirmee(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id,
        ]);

        // Le chef de pôle n'a pas la main sur l'hébergement.
        Livewire::actingAs($this->chefDePole)->test(DossierFiche::class, ['demande' => $demande])->call('autoriserOccupation')->assertForbidden();

        // Admis mais pas encore confirmé : l'occupation ne s'autorise pas.
        Livewire::actingAs($this->gestionnaireFilles)->test(DossierFiche::class, ['demande' => $demande])->call('autoriserOccupation');
        $this->assertFalse($demande->fresh()->occupationAutorisee());
    }

    public function test_le_gestionnaire_confirme_la_place_de_l_admis_pas_le_stagiaire(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id,
        ]);

        // Le chef de pôle, sans la gestion de l'hébergement, ne confirme pas la place.
        Livewire::actingAs($this->chefDePole)->test(DossierFiche::class, ['demande' => $demande])
            ->call('confirmerPlace')->assertForbidden();

        // Le gestionnaire de l'internat confirme la place de l'admis ; le premier paiement attend l'autorisation.
        Livewire::actingAs($this->gestionnaireFilles)->test(DossierFiche::class, ['demande' => $demande])
            ->assertSee('Confirmer la place')
            ->call('confirmerPlace')->assertHasNoErrors();

        $this->assertSame(StatutDemande::Confirme, $demande->fresh()->statut);
        $this->assertNotNull($demande->fresh()->date_confirmation);
        $this->assertDatabaseHas('paiement', ['demande_id' => $demande->id, 'statut' => 'en_attente']);
    }
}
