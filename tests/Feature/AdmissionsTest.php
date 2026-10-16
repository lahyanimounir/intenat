<?php

namespace Tests\Feature;

use App\Domain\Admissions\CalculScore;
use App\Domain\Admissions\Classement;
use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Admissions\Deliberation;
use App\Domain\Admissions\ListeAttente;
use App\Domain\Campaigns\GestionCampagne;
use App\Enums\ListeAdmission;
use App\Enums\NiveauFormation;
use App\Enums\OrigineDecision;
use App\Enums\RaisonDesistement;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Enums\TypeDecision;
use App\Livewire\Personnel\Classement as EcranClassement;
use App\Livewire\Personnel\ListeAttente as EcranListeAttente;
use App\Livewire\Stagiaire\Dossier as EcranDossier;
use App\Models\Campagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use App\Models\NotificationInterne;
use App\Models\Parametre;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Score, classement, délibération, liste d'attente et échéances.
 */
class AdmissionsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Campagne $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
        $this->campagne = $this->creerCampagne($this->filles, ['nombre_places' => 2]);
    }

    /** Dossier complet dont le score vaut 25 (éloigné) + 20 (démuni) + 10 (TS) selon les indicateurs. */
    private function complet(bool $eloigne, bool $demuni, bool $ts = false): Demande
    {
        $stagiaire = $this->creerStagiaire($this->filles, [
            'niveau_formation' => $ts ? NiveauFormation::TechnicienSpecialise : NiveauFormation::Qualification,
        ]);

        return $this->rendreComplet($this->creerDossierRecu($this->campagne, ['eloigne_ou_rural' => $eloigne, 'demuni' => $demuni], $stagiaire));
    }

    /** Validation des admissions par le chef de pôle, telle que le domaine l'applique. */
    private function valider(array $ajustements = [], bool $arbitrage = false): Campagne
    {
        $this->agirEnTantQue($this->chefDePole);

        return app(Deliberation::class)->valider($this->campagne->fresh(), $this->chefDePole, $ajustements, $arbitrage);
    }

    private function appeler(Demande $candidat, ?string $motif = null): Demande
    {
        $this->agirEnTantQue($this->chefDePole);

        return app(ListeAttente::class)->promouvoir($candidat->fresh(), $this->chefDePole, $motif);
    }

    // ------------------------------------------------------------------ Score

    public function test_le_score_est_deterministe_et_son_detail_est_conserve(): void
    {
        $demande = $this->complet(eloigne: true, demuni: true, ts: true);
        $calcul = app(CalculScore::class);

        $this->assertSame(55, $demande->score);
        $this->assertSame(1, $demande->version_grille);
        $this->assertNotNull($demande->date_calcul_score);

        // Recalculer plusieurs fois donne exactement le même total et le même détail.
        $detail = fn () => $demande->detailScore()->orderBy('critere_id')->get(['critere_id', 'points_obtenus'])->toArray();
        $avant = $detail();

        foreach (range(1, 3) as $i) {
            $this->assertSame(55, $calcul->calculer($demande->fresh())->score);
            $this->assertSame($avant, $detail());
        }

        $this->assertSame([20, 25, 10], array_column($avant, 'points_obtenus'));
        $this->assertSame(55, $calcul->maximumAtteignable($this->campagne));
    }

    public function test_une_declaration_ne_rapporte_des_points_que_si_sa_piece_est_acceptee(): void
    {
        $demande = $this->creerDossierRecu($this->campagne, ['demuni' => true]);
        $calcul = app(CalculScore::class);

        // Pièce déposée mais non acceptée : la déclaration « démuni » ne compte pas encore.
        $evaluation = $calcul->evaluer($demande);
        $ligne = collect($evaluation['lignes'])->firstWhere('critere.condition_texte', 'Stagiaire démuni');
        $this->assertSame([true, true, false, 0], [$ligne['declare'], $ligne['piece_requise'], $ligne['piece_acceptee'], $ligne['points']]);

        // Pièce acceptée : les 20 points s'ajoutent aux 10 du niveau Technicien Spécialisé.
        $this->assertSame(10, $evaluation['total']);
        $demande->pieces()->update(['statut' => StatutPiece::Acceptee->value]);
        $this->assertSame(30, $calcul->evaluer($demande->fresh())['total']);
    }

    public function test_le_chef_de_pole_consulte_le_detail_du_score(): void
    {
        $demande = $this->complet(eloigne: true, demuni: false, ts: true);

        $evaluation = app(CalculScore::class)->evaluer($demande);
        $this->assertSame([35, 35, 1, 3], [$demande->score, $evaluation['total'], $demande->version_grille, count($evaluation['lignes'])]);
        $this->assertSame(80, (int) config('internat.score.bareme_maximum'));

        $this->ecran($this->chefDePole, EcranClassement::class)->call('voirScore', $demande->id)
            ->assertSee('Détail du score')->assertSee('Milieu rural ou éloigné')->assertSee('Niveau Technicien Spécialisé');

        // Le score et le rang restent fermés au gestionnaire, et au chef de pôle d'un autre établissement.
        $this->ecran($this->gestionnaireFilles, EcranClassement::class)->assertForbidden();
        $this->ecran(Utilisateur::factory()->chefDePole($this->ailleurs->etablissement_id)->create(), EcranClassement::class)
            ->call('voirScore', $demande->id)->assertForbidden();
    }

    // ------------------------------------------------------------- Classement

    public function test_le_classement_respecte_le_nombre_de_places_et_separe_les_deux_listes(): void
    {
        $a = $this->complet(true, true, true);   // 55
        $b = $this->complet(true, true);         // 45
        $c = $this->complet(true, false);        // 25
        $d = $this->complet(false, true);        // 20
        $nonClasse = $this->creerDossierRecu($this->campagne);

        $etat = app(Classement::class)->recalculer($this->campagne);

        $this->assertSame(['classes' => 4, 'places' => 2, 'ex_aequo_a_la_limite' => false, 'score_limite' => 45], $etat);
        $this->assertSame([1, 2, 3, 4], [$a->fresh()->rang, $b->fresh()->rang, $c->fresh()->rang, $d->fresh()->rang]);
        $this->assertSame(
            [ListeAdmission::Principale, ListeAdmission::Principale, ListeAdmission::Attente, ListeAdmission::Attente],
            [$a->fresh()->liste, $b->fresh()->liste, $c->fresh()->liste, $d->fresh()->liste],
        );

        // Seuls les dossiers complets sont classés.
        $this->assertNull($nonClasse->fresh()->rang);
        $this->assertNull($nonClasse->fresh()->liste);
    }

    public function test_les_internats_filles_et_garcons_ont_des_listes_distinctes(): void
    {
        $garcons = $this->creerCampagne($this->garcons, ['nombre_places' => 1]);
        $fille = $this->complet(true, true);
        $garcon = $this->rendreComplet($this->creerDossierRecu($garcons, ['eloigne_ou_rural' => true]));

        // Chaque genre est classé dans sa campagne : deux premiers rangs, un par internat.
        $this->assertSame([1, 1], [$fille->fresh()->rang, $garcon->fresh()->rang]);

        $this->ecran($this->chefDePole, EcranClassement::class)->set('campagne', (string) $this->campagne->id)
            ->assertSee($fille->numero_dossier)->assertDontSee($garcon->numero_dossier)
            ->set('campagne', (string) $garcons->id)->assertSee($garcon->numero_dossier)->assertDontSee($fille->numero_dossier);
    }

    public function test_une_egalite_a_la_limite_des_places_est_signalee_sans_departage_arbitraire(): void
    {
        $this->complet(true, true);              // 45
        $x = $this->complet(true, false);        // 25
        $y = $this->complet(true, false);        // 25

        $etat = app(Classement::class)->recalculer($this->campagne);

        // Même score, même rang : aucune caractéristique personnelle ne départage.
        $this->assertTrue($etat['ex_aequo_a_la_limite']);
        $this->assertSame([2, 2], [$x->fresh()->rang, $y->fresh()->rang]);

        // La délibération exige alors un arbitrage explicite du chef de pôle.
        $this->campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();
        $this->assertRegle('arbitrage_requis', fn () => $this->valider());
        $this->assertFalse($this->campagne->fresh()->estDeliberee());
        $this->assertTrue($this->valider(arbitrage: true)->estDeliberee());
    }

    public function test_la_regle_de_departage_par_date_de_depot_est_parametrable(): void
    {
        Parametre::where('cle', 'classement.departage')->update(['valeur' => json_encode('date_soumission')]);
        $this->complet(true, true);
        $x = $this->complet(true, false);
        $y = $this->complet(true, false);
        $x->forceFill(['date_soumission' => now()->subDays(5)])->save();
        $y->forceFill(['date_soumission' => now()->subDays(2)])->save();

        $etat = app(Classement::class)->recalculer($this->campagne);

        $this->assertFalse($etat['ex_aequo_a_la_limite']);
        $this->assertSame([2, 3], [$x->fresh()->rang, $y->fresh()->rang]);
    }

    // ----------------------------------------------------------- Délibération

    public function test_seul_le_chef_de_pole_valide_les_admissions(): void
    {
        $this->complet(true, true);
        $this->campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();

        // Sans la permission, l'écran ne s'ouvre même pas : aucune action n'est atteignable.
        foreach ([$this->gestionnaireFilles, $this->directeur, $this->directionRegionale, $this->restauration, $this->admin] as $compte) {
            $this->ecran($compte, EcranClassement::class)->assertForbidden();
            $this->connecter($compte)->get('/admissions/classement')->assertForbidden();
        }

        // Un chef de pôle d'un autre établissement : cette campagne n'existe pas pour lui.
        $autreChef = Utilisateur::factory()->chefDePole($this->ailleurs->etablissement_id)->create();
        $this->ecran($autreChef, EcranClassement::class)->set('campagne', (string) $this->campagne->id)->call('deliberer')->assertNotFound();

        $this->assertContains($this->connecter($this->creerStagiaire($this->filles))->get('/admissions/classement')->status(), [302, 403]);

        $this->assertNull($this->campagne->fresh()->date_deliberation);
        $this->assertSame(0, DecisionAdmission::count());
    }

    public function test_la_deliberation_cree_les_decisions_fixe_les_echeances_et_publie(): void
    {
        $a = $this->complet(true, true, true);
        $b = $this->complet(true, true);
        $c = $this->complet(true, false);
        $this->campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();

        $this->freezeSecond();
        $this->ecran($this->chefDePole, EcranClassement::class)->set('campagne', (string) $this->campagne->id)->call('deliberer')->assertHasNoErrors();
        $this->assertSame('deliberee', $this->campagne->fresh()->phase());

        $this->assertSame(
            [StatutDemande::Admis, StatutDemande::Admis, StatutDemande::EnAttente],
            [$a->fresh()->statut, $b->fresh()->statut, $c->fresh()->statut],
        );

        // Une décision persistante par dossier, avec le score et le rang retenus.
        $decision = DecisionAdmission::where('demande_id', $a->id)->firstOrFail();
        $this->assertSame(
            [OrigineDecision::SelectionInitiale, TypeDecision::Admis, $this->chefDePole->id, 55, 1],
            [$decision->origine, $decision->decision, $decision->valide_par, $decision->score_retenu, $decision->rang_retenu],
        );
        $this->assertSame(3, DecisionAdmission::count());

        // Dix jours pour confirmer ; résultats notifiés à chaque candidat.
        $this->assertEquals(now()->addDays(10), $a->fresh()->date_limite_confirmation);
        $this->assertNull($c->fresh()->date_limite_confirmation);
        $this->assertSame(3, NotificationInterne::where('type', 'resultat')->count());
        $this->assertDatabaseHas('journal_audit', ['action' => 'admission.deliberation', 'entite_id' => $this->campagne->id]);

        // Une seconde validation est refusée : la décision approuvée est figée, comme les places de la campagne.
        $refus = $this->assertRegle('deja_deliberee', fn () => $this->valider(arbitrage: true));
        $this->assertSame(409, $refus->statutHttp);
        $this->assertRegle('campagne_deliberee', fn () => app(GestionCampagne::class)->modifier($this->campagne->fresh(), ['nombre_places' => 50]));
        $this->assertSame(3, DecisionAdmission::count());
    }

    public function test_la_deliberation_est_bloquee_tant_que_des_dossiers_restent_a_instruire(): void
    {
        $this->complet(true, true);
        $this->creerDossierRecu($this->campagne);

        // Campagne encore ouverte, puis clôturée avec un dossier non instruit.
        $this->assertRegle('deliberation_bloquee', fn () => $this->valider(arbitrage: true));
        $this->campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();
        $this->assertRegle('deliberation_bloquee', fn () => $this->valider(arbitrage: true));

        // À l'écran, le refus s'explique et rien n'est validé.
        $this->ecran($this->chefDePole, EcranClassement::class)->set('campagne', (string) $this->campagne->id)->call('deliberer');
        $this->assertNull($this->campagne->fresh()->date_deliberation);
    }

    public function test_un_ecart_a_la_proposition_doit_etre_motive_et_ne_peut_pas_depasser_la_capacite(): void
    {
        $a = $this->complet(true, true, true);
        $b = $this->complet(true, true);
        $c = $this->complet(true, false);
        $this->campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();

        // Sans motif : refusé par le domaine, et dès la saisie à l'écran.
        $this->assertRegle('motif_requis', fn () => $this->valider([$c->id => ['decision' => 'admis', 'motif' => '']]));
        $this->ecran($this->chefDePole, EcranClassement::class)->set('campagne', (string) $this->campagne->id)
            ->call('preparerAjustement', $c->id)->set('decision', 'admis')->call('enregistrerAjustement')->assertHasErrors('motif');

        // Trois admis pour deux places.
        $this->assertRegle('capacite_depassee', fn () => $this->valider([$c->id => ['decision' => 'admis', 'motif' => 'Situation exceptionnelle signalée.']]));

        // Un dossier d'une autre campagne ne s'ajuste pas ici.
        $etranger = $this->rendreComplet($this->creerDossierRecu($this->creerCampagne($this->garcons)));
        $this->assertRegle('ajustement_invalide', fn () => $this->valider([$etranger->id => ['decision' => 'admis', 'motif' => 'Dossier étranger à la campagne.']]));

        // Échange motivé : le troisième est admis, le deuxième passe en liste d'attente.
        $this->valider([
            $c->id => ['decision' => 'admis', 'motif' => 'Situation exceptionnelle signalée.'],
            $b->id => ['decision' => 'liste_attente', 'motif' => 'Place cédée après vérification du dossier.'],
        ]);

        $this->assertSame([StatutDemande::Admis, StatutDemande::EnAttente, StatutDemande::Admis], [$a->fresh()->statut, $b->fresh()->statut, $c->fresh()->statut]);
        $this->assertSame('Situation exceptionnelle signalée.', DecisionAdmission::where('demande_id', $c->id)->value('motif'));
        $this->assertNull(DecisionAdmission::where('demande_id', $a->id)->value('motif'));
    }

    // ------------------------------------------------- Confirmation, échéances

    public function test_la_confirmation_est_refusee_apres_l_echeance_cote_serveur(): void
    {
        $a = $this->complet(true, true);
        $this->deliberer($this->campagne);
        $stagiaire = $a->stagiaire;

        $this->travelTo(now()->addDays(10)->addMinute());
        $this->agirEnTantQue($stagiaire);
        $this->assertRegle('delai_depasse', fn () => app(ConfirmationAdmission::class)->confirmer($a->fresh()));
        $this->ecran($stagiaire, EcranDossier::class)->call('confirmer');
        $this->assertSame(StatutDemande::Admis, $a->fresh()->statut);

        $this->travelBack();
        $this->ecran($stagiaire, EcranDossier::class)->call('confirmer')->assertHasNoErrors();
        $this->assertSame(StatutDemande::Confirme, $a->fresh()->statut);
        $this->assertDatabaseHas('paiement', ['demande_id' => $a->id, 'statut' => 'en_attente']);
    }

    public function test_la_tache_planifiee_libere_les_places_non_confirmees_sans_doublon(): void
    {
        $a = $this->complet(true, true, true);
        $b = $this->complet(true, true);
        $c = $this->complet(true, false);
        $this->deliberer($this->campagne);
        $confirmation = app(ConfirmationAdmission::class);

        // Avant l'échéance : un rappel, envoyé une seule fois même si la tâche repasse.
        $this->travelTo(now()->addDays(8));
        $this->assertSame(2, $confirmation->rappelerEcheances());
        $this->assertSame(0, $confirmation->rappelerEcheances());
        $this->assertSame(0, $confirmation->expirerEcheances());

        $this->agirEnTantQue($a->stagiaire);
        $confirmation->confirmer($a->fresh());

        // Après l'échéance : la place de B est remise à disposition, une seule fois.
        $this->travelTo(now()->addDays(3));
        $this->assertSame(1, $confirmation->expirerEcheances());
        $this->assertSame(0, $confirmation->expirerEcheances());

        $this->assertSame(StatutDemande::DelaiDepasse, $b->fresh()->statut);
        $this->assertSame(StatutDemande::Confirme, $a->fresh()->statut);
        $this->assertSame(1, NotificationInterne::where('stagiaire_id', $b->stagiaire_id)->where('titre', 'Délai de confirmation dépassé')->count());

        // Aucune promotion automatique : le candidat en attente le reste, le chef de pôle est alerté.
        $this->assertSame(StatutDemande::EnAttente, $c->fresh()->statut);
        $this->assertSame(1, NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('type', 'place_libre')->count());
    }

    // --------------------------------------------------------- Liste d'attente

    public function test_la_liste_d_attente_n_est_promue_que_par_decision_du_chef_de_pole(): void
    {
        $a = $this->complet(true, true, true);
        $this->complet(true, true);
        $c = $this->complet(true, false);
        $d = $this->complet(false, true);
        $this->deliberer($this->campagne);

        // Aucune place libre : la promotion est impossible.
        $this->assertRegle('aucune_place', fn () => $this->appeler($c));

        // Un désistement motivé libère une place et alerte le chef de pôle, sans admettre personne.
        $this->ecran($a->stagiaire, EcranDossier::class)->set('raison', 'cout')->call('desister')->assertHasErrors('explication')
            ->set('explication', 'Je ne peux pas assumer les frais cette année.')->call('desister')->assertHasNoErrors();
        $this->assertSame(StatutDemande::Desiste, $a->fresh()->statut);

        $this->assertDatabaseHas('desistement', ['demande_id' => $a->id, 'raison' => 'cout']);
        $this->assertSame(StatutDemande::EnAttente, $c->fresh()->statut);
        $this->assertSame(1, NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('type', 'place_libre')->count());
        $this->assertSame(0, NotificationInterne::where('utilisateur_id', $this->gestionnaireFilles->id)->where('type', 'place_libre')->count());

        // Ni le gestionnaire ni le directeur ne peuvent appeler un candidat : l'écran leur est fermé.
        $this->ecran($this->gestionnaireFilles, EcranListeAttente::class)->assertForbidden();
        $this->ecran($this->directeur, EcranListeAttente::class)->assertForbidden();
        $this->assertSame(StatutDemande::EnAttente, $c->fresh()->statut);

        // Le chef de pôle choisit : s'écarter du mieux classé exige un motif.
        $this->assertSame([1, 2], [$this->campagne->fresh()->placesLibres(), $this->campagne->demandes()->where('statut', StatutDemande::EnAttente->value)->count()]);
        $this->assertRegle('motif_requis', fn () => $this->appeler($d));

        $this->freezeSecond();
        $this->ecran($this->chefDePole, EcranListeAttente::class)->assertSee($c->numero_dossier)->call('promouvoir', $c->id)->assertHasNoErrors();
        $this->assertSame(StatutDemande::Admis, $c->fresh()->statut);

        // Décision tracée, 48 heures pour confirmer, candidat notifié.
        $this->assertEquals(now()->addHours(48), $c->fresh()->date_limite_confirmation);
        $this->assertDatabaseHas('decision_admission', ['demande_id' => $c->id, 'origine' => 'liste_attente', 'decision' => 'admis', 'valide_par' => $this->chefDePole->id]);
        $this->assertDatabaseHas('notification', ['stagiaire_id' => $c->stagiaire_id, 'titre' => 'Une place vous est proposée']);

        // La place est de nouveau tenue : plus de promotion possible.
        $this->assertRegle('aucune_place', fn () => $this->appeler($d, 'Second appel'));
    }

    public function test_un_candidat_appele_de_la_liste_d_attente_dispose_de_48_heures(): void
    {
        $a = $this->complet(true, true, true);
        $this->complet(true, true);
        $c = $this->complet(true, false);
        $this->deliberer($this->campagne);

        $this->agirEnTantQue($a->stagiaire);
        app(ConfirmationAdmission::class)->desister($a->fresh(), RaisonDesistement::Autre, 'Changement de projet de formation.');
        $this->appeler($c);

        $this->travelTo(now()->addHours(49));
        $this->assertSame(1, app(ConfirmationAdmission::class)->expirerEcheances());
        $this->assertSame(StatutDemande::DelaiDepasse, $c->fresh()->statut);
        $this->assertSame(1, $this->campagne->fresh()->placesLibres());
    }
}
