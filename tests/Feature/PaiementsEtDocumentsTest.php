<?php

namespace Tests\Feature;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Catering\Restauration;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Shared\Parametres;
use App\Enums\StatutDemande;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\TypeRepas;
use App\Livewire\Personnel\Paiements;
use App\Livewire\Stagiaire\Paiements as MesPaiements;
use App\Livewire\Stagiaire\Repas;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\ReservationRepas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Suivi des paiements d'hébergement (autorisation, reçu, contrôle, permission
 * d'occuper) et protection des documents privés.
 */
class PaiementsEtDocumentsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Campagne $campagne;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
        $this->campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10, 'frais_mensuels' => 150]);
    }

    /** Admise qui confirme sa place : son premier paiement attend l'autorisation. */
    private function confirme(): Demande
    {
        $demande = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $this->campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id,
            'date_limite_confirmation' => now()->addDays(10),
        ]);
        $this->agirEnTantQue($this->gestionnaireFilles);

        return app(ConfirmationAdmission::class)->confirmer($demande, $this->gestionnaireFilles)->fresh();
    }

    private function recu(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('recu.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    private function autoriser(Paiement $paiement): Paiement
    {
        $this->agirEnTantQue($this->chefDePole);

        return app(SuiviPaiements::class)->autoriser($paiement, $this->chefDePole);
    }

    public function test_les_quatre_etapes_du_paiement_restent_distinctes(): void
    {
        $demande = $this->confirme();
        $paiement = $demande->paiements()->firstOrFail();
        $suivi = app(SuiviPaiements::class);

        // 1. En attente d'autorisation : le stagiaire ne peut pas encore déposer de reçu.
        $this->assertSame([StatutPaiement::EnAttente, '150.00'], [$paiement->statut, $paiement->montant]);
        $this->agirEnTantQue($demande->stagiaire);
        $this->assertRegle('depot_impossible', fn () => $suivi->deposerRecu($paiement->fresh(), $this->recu()));

        // 2. Seul le chef de pôle autorise le paiement.
        $this->ecran($this->gestionnaireFilles, Paiements::class)->call('preparerAutorisation', $paiement->id)->assertForbidden();
        $this->ecran($this->chefDePole, Paiements::class)->call('preparerAutorisation', $paiement->id)->call('delivrer')->assertHasNoErrors();
        $this->assertSame(StatutPaiement::Autorise, $paiement->fresh()->statut);
        $this->assertSame(409, $this->assertRegle('deja_autorise', fn () => $this->autoriser($paiement->fresh()))->statutHttp);
        $this->connecter($this->chefDePole)->get(route('paiements.autorisation', $paiement))->assertOk()->assertHeader('content-type', 'application/pdf');

        // 3. Le stagiaire téléverse son reçu depuis son espace.
        $this->ecran($demande->stagiaire, MesPaiements::class)->set('recus.'.$paiement->id, $this->recu())->assertHasNoErrors();
        $this->assertSame(StatutPaiement::RecuDepose, $paiement->fresh()->statut);

        // 4. Seul le gestionnaire contrôle le reçu ; un refus est motivé et rouvre le dépôt.
        $this->ecran($this->chefDePole, Paiements::class)->call('valider', $paiement->id)->assertForbidden();
        $ecran = $this->ecran($this->gestionnaireFilles, Paiements::class);
        $ecran->call('refuser', $paiement->id)->assertHasErrors('motif');
        $ecran->call('refuser', $paiement->id, 'Reçu illisible')->assertHasNoErrors();
        $this->assertSame(StatutPaiement::Refuse, $paiement->fresh()->statut);

        $this->ecran($demande->stagiaire, MesPaiements::class)->set('recus.'.$paiement->id, $this->recu())->assertHasNoErrors();
        $this->ecran($this->gestionnaireFilles, Paiements::class)->call('valider', $paiement->id)->assertHasNoErrors();

        $paiement->refresh();
        $this->assertSame([StatutPaiement::Valide, $this->chefDePole->id, $this->gestionnaireFilles->id], [$paiement->statut, $paiement->autorise_par, $paiement->valide_par]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'paiement.autorisation', 'utilisateur_id' => $this->chefDePole->id]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'paiement.controle_recu', 'utilisateur_id' => $this->gestionnaireFilles->id]);
    }

    public function test_l_hebergement_peut_etre_autorise_sans_recu_et_ce_fait_est_trace(): void
    {
        $demande = $this->confirme();
        $paiement = $demande->paiements()->firstOrFail();
        $affectations = app(AffectationLits::class);

        // Sans autorisation de paiement, l'occupation n'est pas autorisable.
        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertRegle('occupation_non_autorisable', fn () => $affectations->autoriserOccupation($demande->fresh(), $this->gestionnaireFilles));

        $this->autoriser($paiement);

        // Paiement autorisé mais reçu absent : le paiement n'est pas bloquant.
        $this->agirEnTantQue($this->gestionnaireFilles);
        $demande = $affectations->autoriserOccupation($demande->fresh(), $this->gestionnaireFilles);
        $this->assertTrue($demande->occupationAutorisee());
        $this->assertSame($this->gestionnaireFilles->id, $demande->occupation_autorisee_par);
        $this->assertSame(StatutPaiement::Autorise, $paiement->fresh()->statut);
        $this->assertDatabaseHas('journal_audit', ['action' => 'hebergement.occupation_autorisee', 'entite_id' => $demande->id]);

        // Si l'établissement exige le reçu validé, la même demande est refusée.
        $autre = $this->confirme();
        $this->autoriser($autre->paiements()->first());
        Parametre::where('cle', 'occupation.exiger_recu_valide')->update(['valeur' => json_encode(true)]);
        app(Parametres::class)->oublier();

        $this->agirEnTantQue($this->gestionnaireFilles);
        $refus = $this->assertRegle('occupation_non_autorisable', fn () => $affectations->autoriserOccupation($autre->fresh(), $this->gestionnaireFilles));
        $this->assertSame('Aucun reçu de paiement validé.', $refus->contexte['obstacles'][0]);
    }

    public function test_les_autorisations_mensuelles_sont_generees_une_seule_fois_par_resident_et_par_mois(): void
    {
        $lits = $this->creerLits($this->filles, 1, 2);
        $this->creerResident($this->campagne, $lits[0]);
        $this->creerResident($this->campagne, $lits[1]);
        $suivi = app(SuiviPaiements::class);
        $moisSuivant = now()->addMonth()->startOfMonth();

        $this->assertSame(2, $suivi->genererAutorisationsMensuelles($moisSuivant));
        $this->assertSame(0, $suivi->genererAutorisationsMensuelles($moisSuivant));

        $generes = Paiement::where('periode', $moisSuivant->format('Y-m'))->get();
        $this->assertCount(2, $generes);
        $this->assertTrue($generes->every(fn (Paiement $p) => $p->genere_auto && $p->statut === StatutPaiement::Autorise && $p->autorise_par === null));
    }

    public function test_la_restauration_est_bloquee_a_partir_du_5_pour_un_recu_manquant(): void
    {
        $lit = $this->creerLits($this->filles, 1, 1)[0];
        $affectation = $this->creerResident($this->campagne, $lit);
        $demande = $affectation->demande;
        $suivi = app(SuiviPaiements::class);

        $le4 = now()->addMonth()->startOfMonth()->addDays(3);
        $le5 = $le4->copy()->addDay();
        $suivi->genererAutorisationsMensuelles($le4);

        $this->assertFalse($suivi->restaurationBloquee($demande, $le4));
        $this->assertTrue($suivi->restaurationBloquee($demande, $le5));

        // Reçu déposé : l'accès est rétabli.
        Paiement::where('demande_id', $demande->id)->where('periode', $le5->format('Y-m'))->update(['statut' => StatutPaiement::RecuDepose->value]);
        $this->assertFalse($suivi->restaurationBloquee($demande, $le5));

        // La réservation d'un repas applique bien ce blocage, à l'écran comme dans le domaine.
        Paiement::where('demande_id', $demande->id)->where('periode', $le5->format('Y-m'))->update(['statut' => StatutPaiement::Autorise->value]);
        $menu = Menu::create([
            'internat_id' => $this->filles->id, 'date_repas' => $le5->toDateString(), 'type_repas' => TypeRepas::Dejeuner,
            'description' => 'Menu de test', 'statut' => StatutMenu::Propose, 'cree_par' => $this->restauration->id,
            'transmis_le' => now(), 'transmis_par' => $this->restauration->id,
        ]);
        $this->agirEnTantQue($this->restauration);
        app(Restauration::class)->validerMenu($menu, $this->chefDePole);

        $this->agirEnTantQue($demande->stagiaire);
        $this->assertRegle('reservation_impossible', fn () => app(Restauration::class)->reserver($demande->stagiaire, $menu->fresh()));
        $this->ecran($demande->stagiaire, Repas::class)->call('reserver', $menu->id);
        $this->assertSame(0, ReservationRepas::count());
    }

    public function test_les_pieces_et_les_recus_ne_sont_accessibles_qu_aux_personnes_autorisees(): void
    {
        $dossier = $this->creerDossierRecu($this->campagne);
        $piece = $dossier->pieces[0];
        $autreStagiaire = $this->creerStagiaire($this->filles);
        $this->creerDossierRecu($this->campagne, [], $autreStagiaire);

        // Sans session : renvoi à la connexion.
        $this->get(route('dossiers.piece', $piece))->assertRedirect(route('backend.connexion'));
        $this->get(route('stagiaire.dossier.piece', $piece))->assertRedirect(route('connexion'));

        // Le propriétaire lit sa pièce ; un autre stagiaire ne peut pas, même en devinant l'identifiant.
        $this->connecter($dossier->stagiaire)->get(route('stagiaire.dossier.piece', $piece))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->connecter($autreStagiaire)->get(route('stagiaire.dossier.piece', $piece))->assertNotFound();
        $this->assertContains($this->get(route('dossiers.piece', $piece))->status(), [302, 403]);

        // Le gestionnaire de l'internat lit la pièce (consultation tracée) ; celui de l'autre internat, non.
        $this->connecter($this->gestionnaireFilles)->get(route('dossiers.piece', $piece))->assertOk();
        $this->assertDatabaseHas('journal_audit', ['action' => 'piece.consultation', 'entite_id' => $piece->id, 'utilisateur_id' => $this->gestionnaireFilles->id]);
        $this->connecter($this->gestionnaireGarcons)->get(route('dossiers.piece', $piece))->assertForbidden();
        $this->connecter($this->gestionnaireAilleurs)->get(route('dossiers.piece', $piece))->assertForbidden();

        // Les rôles sans permission sur les dossiers n'y accèdent pas, même dans leur périmètre.
        $this->connecter($this->restauration)->get(route('dossiers.piece', $piece))->assertForbidden();
        $this->connecter($this->directeur)->get(route('dossiers.piece', $piece))->assertForbidden();

        // Le fichier n'existe sur aucun disque public et son chemin n'apparaît jamais dans la page du dossier.
        $this->assertFalse(Storage::disk('public')->exists($piece->fichier));
        $page = $this->connecter($this->gestionnaireFilles)->get(route('dossiers.fiche', $dossier))->assertOk()->getContent();
        $this->assertStringNotContainsString($piece->fichier, $page);
        $this->assertStringNotContainsString('storage/', $page);
    }

    public function test_un_recu_n_est_lisible_que_par_son_proprietaire_et_le_personnel_du_perimetre(): void
    {
        $demande = $this->confirme();
        $paiement = $this->autoriser($demande->paiements()->firstOrFail());
        $this->ecran($demande->stagiaire, MesPaiements::class)->set('recus.'.$paiement->id, $this->recu())->assertHasNoErrors();

        $this->connecter($demande->stagiaire)->get(route('stagiaire.paiements.recu', $paiement))->assertOk();

        // Une autre stagiaire ne lit pas ce reçu et ne dépose rien à la place de la première.
        $intrus = $this->creerStagiaire($this->filles);
        $this->connecter($intrus)->get(route('stagiaire.paiements.recu', $paiement))->assertNotFound();
        $this->ecran($intrus, MesPaiements::class)->set('recus.'.$paiement->id, $this->recu())->assertNotFound();

        $this->connecter($this->gestionnaireFilles)->get(route('paiements.recu', $paiement))->assertOk();
        $this->connecter($this->gestionnaireGarcons)->get(route('paiements.recu', $paiement))->assertForbidden();
        $this->ecran($this->gestionnaireGarcons, Paiements::class)->call('valider', $paiement->id)->assertForbidden();
        $this->assertSame(StatutPaiement::RecuDepose, $paiement->fresh()->statut);
    }

    public function test_le_gestionnaire_ne_liste_que_les_paiements_de_son_internat(): void
    {
        $fille = $this->confirme();
        $campagneGarcons = $this->creerCampagne($this->garcons);
        $garcon = Demande::factory()->deposee(StatutDemande::Confirme)->create([
            'campagne_id' => $campagneGarcons->id, 'stagiaire_id' => $this->creerStagiaire($this->garcons)->id,
        ]);
        Paiement::create(['demande_id' => $garcon->id, 'periode' => now()->format('Y-m'), 'montant' => 100, 'statut' => StatutPaiement::EnAttente]);

        $this->ecran($this->gestionnaireFilles, Paiements::class)->assertSee($fille->numero_dossier)->assertDontSee($garcon->numero_dossier);
        $this->ecran($this->chefDePole, Paiements::class)->assertSee($fille->numero_dossier)->assertSee($garcon->numero_dossier);
        $this->assertSame([$fille->id], Paiement::visiblesPar($this->gestionnaireFilles)->pluck('demande_id')->all());

        // Autorisation groupée : une ligne étrangère au périmètre est ignorée, pas traitée.
        $etranger = Paiement::create([
            'demande_id' => Demande::factory()->deposee(StatutDemande::Confirme)->create([
                'campagne_id' => $this->creerCampagne($this->ailleurs)->id, 'stagiaire_id' => $this->creerStagiaire($this->ailleurs)->id,
            ])->id,
            'periode' => now()->format('Y-m'), 'montant' => 100, 'statut' => StatutPaiement::EnAttente,
        ]);

        $this->ecran($this->chefDePole, Paiements::class)->set('selection', Paiement::pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('autoriserSelection')->assertHasNoErrors();

        $this->assertSame(2, Paiement::where('statut', StatutPaiement::Autorise->value)->count());
        $this->assertSame(StatutPaiement::EnAttente, $etranger->fresh()->statut);
    }
}
