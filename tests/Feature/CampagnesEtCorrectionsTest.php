<?php

namespace Tests\Feature;

use App\Domain\Admissions\CalculScore;
use App\Domain\Campaigns\CorrectionCritere;
use App\Domain\Campaigns\GestionCampagne;
use App\Domain\Campaigns\GrilleCampagne;
use App\Enums\DeclencheurCritere;
use App\Enums\StatutCampagne;
use App\Enums\StatutModificationCritere;
use App\Livewire\Personnel\Approbations;
use App\Livewire\Personnel\CampagneFiche;
use App\Livewire\Personnel\Campagnes;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\ModificationCritere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Cycle de vie des campagnes et correction approuvée des critères
 * (chef de pôle → directeur de complexe → direction régionale).
 */
class CampagnesEtCorrectionsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_le_chef_de_pole_cree_une_campagne_par_internat_a_partir_de_la_grille_de_reference(): void
    {
        CritereReference::create([
            'categorie' => 'social', 'declencheur' => 'demuni', 'valeur_attendue' => '', 'condition_texte' => 'Stagiaire démuni',
            'points' => 20, 'piece_libelle' => 'Attestation sociale', 'actif' => true,
        ]);
        CritereReference::create([
            'categorie' => 'geographique', 'declencheur' => 'eloigne_ou_rural', 'valeur_attendue' => '', 'condition_texte' => 'Inactif',
            'points' => 99, 'actif' => false,
        ]);

        $saisir = fn ($ecran, array $places, string $annee = '2026-2027') => $ecran->call('preparer')
            ->set('nouvelle.annee', $annee)->set('nouvelle.date_ouverture', now()->toDateString())->set('nouvelle.date_cloture', now()->addMonth()->toDateString())
            ->set('nouvelle.frais_mensuels', '150')->set('places', $places)->call('creer');

        $this->ecran($this->gestionnaireFilles, Campagnes::class)->call('preparer')->assertForbidden();
        $saisir($this->ecran($this->chefDePole, Campagnes::class), [$this->filles->id => '3', $this->garcons->id => '2'])->assertHasNoErrors();

        // Places par genre ; grille active recopiée, pièce conditionnelle créée.
        $filles = Campagne::where('internat_id', $this->filles->id)->firstOrFail();
        $this->assertSame([3, 2], [$filles->nombre_places, Campagne::where('internat_id', $this->garcons->id)->value('nombre_places')]);
        $this->assertSame(StatutCampagne::Preparee, $filles->statut);
        $this->assertSame(['Stagiaire démuni'], $filles->criteres->pluck('condition_texte')->all());
        $this->assertSame(['Attestation sociale'], $filles->typesPieces->pluck('libelle')->all());

        // Pas de doublon pour la même année ; pas de campagne hors périmètre ; année sur deux ans consécutifs.
        $donnees = [
            'annee' => '2026-2027', 'date_ouverture' => now()->toDateString(), 'date_cloture' => now()->addMonth()->toDateString(), 'frais_mensuels' => 150,
            'internats' => [['internat_id' => $this->filles->id, 'nombre_places' => 3]],
        ];
        $this->agirEnTantQue($this->chefDePole);
        $refus = $this->assertRegle('campagne_existante', fn () => app(GestionCampagne::class)->creer($this->chefDePole, $donnees));
        $this->assertSame(409, $refus->statutHttp);

        $saisir($this->ecran($this->chefDePole, Campagnes::class), [$this->ailleurs->id => '1'], '2027-2028')->assertForbidden();
        $saisir($this->ecran($this->chefDePole, Campagnes::class), [$this->filles->id => '3'], '2026-2028')->assertHasErrors('nouvelle.annee');
        $this->assertSame(2, Campagne::count());
    }

    public function test_l_ouverture_est_controlee_puis_la_campagne_suit_ses_transitions(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee, 'nombre_places' => 5, 'frais_mensuels' => null]);
        $gestion = app(GestionCampagne::class);
        $this->agirEnTantQue($this->chefDePole);

        // Référentiel vide et frais non renseignés : ouverture bloquée, avec les raisons.
        $refus = $this->assertRegle('ouverture_bloquee', fn () => $gestion->ouvrir($campagne->fresh()));
        $this->assertContains('Les frais mensuels d\'internat ne sont pas renseignés.', $refus->contexte['blocages']);
        $this->assertContains('5 places offertes pour 0 lit(s) éligible(s) dans le référentiel de l\'internat.', $refus->contexte['blocages']);

        $this->creerLits($this->filles, 3, 2);
        $gestion->modifier($campagne->fresh(), ['frais_mensuels' => 150]);
        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $campagne])->call('transiter', 'ouvrir')->assertHasNoErrors();
        $this->assertSame(StatutCampagne::Ouverte, $campagne->fresh()->statut);

        // Ouverte : pièces et grille verrouillées, seuls clôture et places restent ajustables.
        $this->agirEnTantQue($this->chefDePole);
        $this->assertRegle('campagne_verrouillee', fn () => app(GrilleCampagne::class)->enregistrerTypePiece($campagne->fresh(), null, [
            'libelle' => 'Nouvelle pièce', 'obligatoire' => true, 'formats_acceptes' => 'PDF',
        ]));
        $this->assertRegle('champ_verrouille', fn () => $gestion->modifier($campagne->fresh(), ['date_ouverture' => now()->subYear()->toDateString()]));
        $this->assertSame(6, $gestion->modifier($campagne->fresh(), ['nombre_places' => 6])->nombre_places);

        // Transitions dans l'ordre uniquement.
        $this->assertRegle('transition_interdite', fn () => $gestion->archiver($campagne->fresh()));
        $this->assertRegle('transition_interdite', fn () => $gestion->ouvrir($campagne->fresh()));
        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $campagne])->call('transiter', 'cloturer')->assertHasNoErrors();
        $this->assertSame('instruction', $campagne->fresh()->phase());

        $this->agirEnTantQue($this->chefDePole);
        $this->assertRegle('suppression_interdite', fn () => $gestion->supprimer($campagne->fresh()));
        $this->assertNotNull($campagne->fresh());
    }

    public function test_les_places_offertes_excluent_les_chambres_isolement_et_infirmerie(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee, 'nombre_places' => 3]);
        $lits = $this->creerLits($this->filles, 2, 2);
        $lits[0]->chambre->forceFill(['type_chambre' => 'infirmerie'])->save();

        $this->assertSame(2, app(GestionCampagne::class)->litsEligibles($this->filles->id));

        // Trois places pour deux lits éligibles : l'ouverture est refusée.
        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $campagne])->call('transiter', 'ouvrir');
        $this->assertSame(StatutCampagne::Preparee, $campagne->fresh()->statut);
    }

    public function test_les_campagnes_echues_sont_cloturees_par_la_tache_planifiee(): void
    {
        $echue = $this->creerCampagne($this->filles, ['date_cloture' => now()->subDay()->toDateString()]);
        $enCours = $this->creerCampagne($this->garcons);

        $this->artisan('internat:cloturer-campagnes')->assertSuccessful();

        $this->assertSame(StatutCampagne::Cloturee, $echue->fresh()->statut);
        $this->assertSame(StatutCampagne::Ouverte, $enCours->fresh()->statut);
    }

    public function test_seul_l_administrateur_saisit_la_grille_d_une_campagne_en_preparation(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['statut' => StatutCampagne::Preparee]);
        $saisir = fn ($ecran, string $categorie, string $declencheur, string $condition, int $points) => $ecran->call('preparerCritere')
            ->set('critere.categorie', $categorie)->set('critere.declencheur', $declencheur)->set('critere.condition', $condition)->set('critere.points', $points)
            ->call('enregistrerCritere');

        // Le chef de pôle gère la campagne, pas sa grille.
        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $campagne])->call('preparerCritere')->assertForbidden();
        $saisir($this->ecran($this->admin, CampagneFiche::class, ['campagne' => $campagne]), 'familial', 'orphelin_ou_divorce', 'Orphelin ou parents divorcés', 15)->assertHasNoErrors();
        $this->assertSame(1, Critere::where('campagne_id', $campagne->id)->where('declencheur', 'orphelin_ou_divorce')->count());

        // Une seule règle par donnée du dossier ; le critère « Formation » précise un niveau.
        $saisir($this->ecran($this->admin, CampagneFiche::class, ['campagne' => $campagne]), 'familial', 'orphelin_ou_divorce', 'Doublon', 15);
        $this->assertSame(1, Critere::where('campagne_id', $campagne->id)->where('declencheur', 'orphelin_ou_divorce')->count());
        $saisir($this->ecran($this->admin, CampagneFiche::class, ['campagne' => $campagne]), 'formation', 'niveau_formation', 'Niveau T', 5)->assertHasErrors('critere.valeur_attendue');

        // Le barème ne peut pas dépasser 80 : l'ouverture le vérifie.
        $saisir($this->ecran($this->admin, CampagneFiche::class, ['campagne' => $campagne]), 'physique', 'besoins_specifiques', 'Besoins spécifiques', 40)->assertHasNoErrors();
        $this->assertSame(110, app(CalculScore::class)->maximumAtteignable($campagne->fresh()));

        $this->creerLits($this->filles, 2, 2);
        $this->agirEnTantQue($this->chefDePole);
        $this->assertRegle('ouverture_bloquee', fn () => app(GestionCampagne::class)->ouvrir($campagne->fresh()));
    }

    public function test_une_correction_de_critere_passe_par_deux_approbations_avant_de_s_appliquer(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 1]);
        $critere = Critere::where('campagne_id', $campagne->id)->where('declencheur', DeclencheurCritere::EloigneOuRural->value)->firstOrFail();

        // Deux dossiers complets : A (éloignée, TS) = 35 ; B (démunie, TS) = 30.
        $a = $this->rendreComplet($this->creerDossierRecu($campagne, ['eloigne_ou_rural' => true]));
        $b = $this->rendreComplet($this->creerDossierRecu($campagne, ['demuni' => true]));
        $this->assertSame([35, 1, 30, 2], [$a->fresh()->score, $a->fresh()->rang, $b->fresh()->score, $b->fresh()->rang]);

        // Le chef de pôle propose : le critère reste inchangé tant que la chaîne n'est pas complète.
        $this->ecran($this->gestionnaireFilles, CampagneFiche::class, ['campagne' => $campagne])->call('preparerCorrection', $critere->id)->assertForbidden();
        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $campagne])->call('preparerCorrection', $critere->id)
            ->set('correction.nouvelle_valeur', '5')->set('correction.motif', 'Erreur de saisie : la grille prévoit 5 points.')
            ->call('proposerCorrection')->assertHasNoErrors();

        $modification = ModificationCritere::sole();
        $this->assertSame(StatutModificationCritere::EnAttenteCmc, $modification->statut);
        $this->assertSame(25, $critere->fresh()->points);

        $this->agirEnTantQue($this->chefDePole);
        $refus = $this->assertRegle('correction_en_cours', fn () => app(CorrectionCritere::class)->proposer($critere->fresh(), 'points', '5', 'Seconde proposition identique.', $this->chefDePole));
        $this->assertSame(409, $refus->statutHttp);

        // Le chef de pôle ne peut pas approuver sa propre proposition ; la région ne peut pas sauter le niveau CMC.
        $this->ecran($this->chefDePole, Approbations::class)->assertForbidden();
        $this->ecran($this->directionRegionale, Approbations::class)->assertDontSee('Erreur de saisie : la grille prévoit 5 points.')
            ->call('approuver', $modification->id)->assertForbidden();

        // Niveau 1 : directeur de complexe / CMC.
        $this->ecran($this->directeur, Approbations::class)->assertSee('Erreur de saisie : la grille prévoit 5 points.')->call('approuver', $modification->id)->assertHasNoErrors();
        $this->assertSame(StatutModificationCritere::ApprouveCmc, $modification->fresh()->statut);
        $this->ecran($this->directeur, Approbations::class)->call('approuver', $modification->id)->assertForbidden();
        $this->assertSame(25, $critere->fresh()->points);
        $this->assertSame(35, $a->fresh()->score);

        // Niveau 2 : direction régionale. La correction s'applique et les scores sont recalculés.
        $this->ecran($this->directionRegionale, Approbations::class)->call('approuver', $modification->id)->assertHasNoErrors();
        $this->assertSame(StatutModificationCritere::ApprouveRegional, $modification->fresh()->statut);

        $this->assertSame(5, $critere->fresh()->points);
        $this->assertSame(2, $campagne->fresh()->version_grille);
        $this->assertSame([15, 2, 2], [$a->fresh()->score, $a->fresh()->rang, $a->fresh()->version_grille]);
        $this->assertSame([30, 1], [$b->fresh()->score, $b->fresh()->rang]);

        $modification->refresh();
        $this->assertSame(
            [$this->chefDePole->id, $this->directeur->id, $this->directionRegionale->id],
            [$modification->demande_par, $modification->approuve_cmc_par, $modification->approuve_regional_par],
        );
        $this->assertDatabaseHas('journal_audit', ['action' => 'critere.correction_appliquee', 'utilisateur_id' => $this->directionRegionale->id]);
    }

    public function test_une_correction_peut_etre_rejetee_avec_motif_et_ne_s_applique_alors_pas(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $critere = $campagne->criteres()->where('declencheur', 'eloigne_ou_rural')->firstOrFail();

        $this->agirEnTantQue($this->chefDePole);
        $modification = app(CorrectionCritere::class)->proposer($critere, 'condition', 'Milieu rural ou plus de 10 km', 'Préciser la distance retenue.', $this->chefDePole);

        $ecran = $this->ecran($this->directeur, Approbations::class);
        $ecran->call('rejeter', $modification->id)->assertHasErrors('motif');
        $this->assertSame(StatutModificationCritere::EnAttenteCmc, $modification->fresh()->statut);
        $ecran->call('rejeter', $modification->id, 'Formulation non conforme à la grille nationale.')->assertHasNoErrors();

        $this->assertSame('Milieu rural ou éloigné', $critere->fresh()->condition_texte);
        $this->assertSame(StatutModificationCritere::Rejete, $modification->fresh()->statut);
        $this->assertSame(1, $campagne->fresh()->version_grille);
    }

    public function test_une_correction_ne_peut_ni_depasser_le_bareme_ni_viser_une_campagne_deliberee(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $critere = $campagne->criteres()->where('declencheur', 'eloigne_ou_rural')->firstOrFail();
        $proposer = fn (string $valeur, string $motif) => fn () => app(CorrectionCritere::class)->proposer($critere->fresh(), 'points', $valeur, $motif, $this->chefDePole);
        $this->agirEnTantQue($this->chefDePole);

        // 20 + 60 + 10 = 90 > 80.
        $this->assertRegle('bareme_depasse', $proposer('60', 'Tentative au-delà du barème.'));
        $this->assertRegle('valeur_identique', $proposer('25', 'Valeur identique à l\'actuelle.'));

        $this->rendreComplet($this->creerDossierRecu($campagne));
        $this->deliberer($campagne);
        $this->agirEnTantQue($this->chefDePole);
        $this->assertRegle('campagne_deliberee', $proposer('10', 'Après la délibération.'));
        $this->assertSame(0, ModificationCritere::count());
    }
}
