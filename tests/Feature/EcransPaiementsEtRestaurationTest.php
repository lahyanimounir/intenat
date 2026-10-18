<?php

namespace Tests\Feature;

use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Catering\Restauration;
use App\Domain\Payments\SuiviPaiements;
use App\Enums\StatutDemande;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use App\Livewire\Personnel\Menus;
use App\Livewire\Personnel\Paiements;
use App\Livewire\Personnel\ServiceDesRepas;
use App\Livewire\Personnel\StatistiquesRepas;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\JournalAudit;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use App\Models\ServiceRepas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Paiement des frais d'hébergement et restauration dans la nouvelle interface.
 * Autoriser, déposer et contrôler restent trois gestes de trois personnes ; un
 * menu n'est diffusé qu'une fois validé ; l'effectif confirmé fige les
 * réservations ; un écart entre confirmé et servi se voit.
 */
class EcransPaiementsEtRestaurationTest extends TestCase
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

    private function pdf(string $nom = 'recu.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    /** Admise qui confirme sa place : son premier paiement attend l'autorisation du chef de pôle. */
    private function paiementEnAttente(?Campagne $campagne = null): Paiement
    {
        $campagne ??= $this->campagne;
        $demande = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($campagne->internat)->id,
            'date_limite_confirmation' => now()->addDays(10),
        ]);
        $gestionnaire = $campagne->internat_id === $this->garcons->id ? $this->gestionnaireGarcons : $this->gestionnaireFilles;
        $this->agirEnTantQue($gestionnaire);
        app(ConfirmationAdmission::class)->confirmer($demande, $gestionnaire);

        return $demande->paiements()->firstOrFail();
    }

    /* ================================================================ Paiements */

    public function test_autoriser_deposer_et_controler_sont_trois_gestes_distincts(): void
    {
        $paiement = $this->paiementEnAttente();
        $stagiaire = $paiement->demande->stagiaire;

        // 1. Seul le chef de pôle délivre l'autorisation ; la période et le montant se contrôlent.
        Livewire::actingAs($this->gestionnaireFilles)->test(Paiements::class)->assertSee($stagiaire->nom)->assertSee('Autorisation à délivrer')
            ->call('preparerAutorisation', $paiement->id)->assertForbidden();

        Livewire::actingAs($this->chefDePole)->test(Paiements::class)->call('preparerAutorisation', $paiement->id)
            ->assertSet('autorisation.montant', '150.00')
            ->set('autorisation.periode', '2026-13')->set('autorisation.montant', '-5')->call('delivrer')->assertHasErrors(['autorisation.periode', 'autorisation.montant'])
            ->set('autorisation.periode', $paiement->periode)->set('autorisation.montant', '175.50')->call('delivrer')->assertHasNoErrors()->assertSet('dialogue', null);

        $paiement->refresh();
        $this->assertSame([StatutPaiement::Autorise, '175.50', $this->chefDePole->id], [$paiement->statut, $paiement->montant, $paiement->autorise_par]);

        // Une autorisation ne se délivre pas deux fois.
        Livewire::actingAs($this->chefDePole)->test(Paiements::class)->call('preparerAutorisation', $paiement->id)->call('delivrer');
        $this->assertSame('175.50', $paiement->fresh()->montant);

        // 2. Le stagiaire dépose son reçu.
        $this->agirEnTantQue($stagiaire);
        app(SuiviPaiements::class)->deposerRecu($paiement, $this->pdf());

        // 3. Seul le gestionnaire contrôle le reçu ; un refus se motive.
        Livewire::actingAs($this->chefDePole)->test(Paiements::class)->call('valider', $paiement->id)->assertForbidden();
        Livewire::actingAs($this->gestionnaireGarcons)->test(Paiements::class)->assertDontSee($stagiaire->nom)->call('valider', $paiement->id)->assertForbidden();

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Paiements::class)->set('statut', StatutPaiement::RecuDepose->value)->assertSee($stagiaire->nom);
        $ecran->call('refuser', $paiement->id, 'non')->assertHasErrors('motif');
        $this->assertSame(StatutPaiement::RecuDepose, $paiement->fresh()->statut);
        $ecran->call('refuser', $paiement->id, 'Reçu illisible.')->assertHasNoErrors();
        $this->assertSame([StatutPaiement::Refuse, 'Reçu illisible.'], [$paiement->fresh()->statut, $paiement->fresh()->motif_refus]);

        $this->agirEnTantQue($stagiaire);
        app(SuiviPaiements::class)->deposerRecu($paiement->fresh(), $this->pdf('recu-2.pdf'));
        Livewire::actingAs($this->gestionnaireFilles)->test(Paiements::class)->call('voirRecu', $paiement->id)->assertSet('dialogue', 'recu')
            ->call('fermerDialogue')->call('valider', $paiement->id)->assertHasNoErrors();
        $this->assertSame([StatutPaiement::Valide, $this->gestionnaireFilles->id], [$paiement->fresh()->statut, $paiement->fresh()->valide_par]);
    }

    public function test_recu_et_autorisation_ne_se_lisent_que_dans_le_perimetre(): void
    {
        $paiement = $this->paiementEnAttente();
        $this->agirEnTantQue($this->chefDePole);
        app(SuiviPaiements::class)->autoriser($paiement, $this->chefDePole);

        // Pas de reçu : rien à lire. L'autorisation, elle, est un document généré.
        $this->connecter($this->gestionnaireFilles)->get(route('paiements.recu', $paiement))->assertNotFound();
        $this->get(route('paiements.autorisation', $paiement))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->agirEnTantQue($paiement->demande->stagiaire);
        app(SuiviPaiements::class)->deposerRecu($paiement->fresh(), $this->pdf());

        $this->connecter($this->gestionnaireFilles)->get(route('paiements.recu', $paiement))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue(JournalAudit::where('action', 'paiement.consultation_recu')->exists());
        $this->connecter($this->gestionnaireGarcons)->get(route('paiements.recu', $paiement))->assertForbidden();
        $this->connecter($this->gestionnaireGarcons)->get(route('paiements.autorisation', $paiement))->assertForbidden();

        // Le responsable de la restauration ne suit pas les paiements.
        $this->connecter($this->restauration)->get('/paiements')->assertForbidden();
        $this->connecter($this->restauration)->get(route('paiements.recu', $paiement))->assertForbidden();
        $this->connecter($this->chefDePole)->get('/paiements')->assertOk()->assertSee('Suivi des paiements');
    }

    public function test_l_autorisation_groupee_ignore_ce_qui_n_est_pas_a_autoriser(): void
    {
        $un = $this->paiementEnAttente();
        $deux = $this->paiementEnAttente();
        $dejaAutorise = $this->paiementEnAttente();
        $this->agirEnTantQue($this->chefDePole);
        app(SuiviPaiements::class)->autoriser($dejaAutorise, $this->chefDePole);
        $ailleurs = $this->paiementEnAttente($this->creerCampagne($this->ailleurs, ['nombre_places' => 5]));

        // Le gestionnaire n'autorise pas, même en appelant l'action.
        Livewire::actingAs($this->gestionnaireFilles)->test(Paiements::class)->set('selection', [(string) $un->id])->call('autoriserSelection')->assertForbidden();

        $ecran = Livewire::actingAs($this->chefDePole)->test(Paiements::class);
        $ecran->call('autoriserSelection');
        $this->assertSame(StatutPaiement::EnAttente, $un->fresh()->statut);

        $ecran->set('selection', [(string) $un->id, (string) $deux->id, (string) $dejaAutorise->id, (string) $ailleurs->id, 'x'])
            ->call('autoriserSelection')->assertHasNoErrors()->assertSet('selection', []);

        $this->assertSame(StatutPaiement::Autorise, $un->fresh()->statut);
        $this->assertSame(StatutPaiement::Autorise, $deux->fresh()->statut);
        // Hors périmètre : intact.
        $this->assertSame(StatutPaiement::EnAttente, $ailleurs->fresh()->statut);

        // Filtres : statut, recherche, période.
        $ecran->set('statut', StatutPaiement::EnAttente->value)->assertDontSee($un->demande->numero_dossier);
        $ecran->set('statut', '')->set('recherche', $un->demande->stagiaire->cef)->assertSee($un->demande->numero_dossier)->assertDontSee($deux->demande->numero_dossier);
        $ecran->set('recherche', '')->set('periode', '1999-01')->assertSee('Aucun paiement ne correspond aux filtres');
    }

    /* ============================================================= Restauration */

    private function menu(string $jour, TypeRepas $repas = TypeRepas::Dejeuner, bool $valide = true): Menu
    {
        $menu = Menu::create([
            'internat_id' => $this->filles->id, 'date_repas' => $jour, 'type_repas' => $repas, 'description' => 'Tajine de légumes',
            'statut' => StatutMenu::Propose, 'cree_par' => $this->restauration->id,
        ]);

        if ($valide) {
            $this->agirEnTantQue($this->restauration);
            app(Restauration::class)->validerMenu($menu, $this->restauration);
        }

        return $menu->fresh();
    }

    public function test_un_menu_se_propose_puis_se_valide_et_ne_se_modifie_plus(): void
    {
        $demain = now()->addDay()->toDateString();
        $ecran = Livewire::actingAs($this->restauration)->test(Menus::class)->assertSee('Cette semaine');

        // Par défaut, le repas est proposé dans les deux internats de l'établissement.
        $ecran->call('proposer', $demain, TypeRepas::Diner->value)->assertSet('saisie.repas', 'diner')
            ->call('enregistrer')->assertHasErrors('saisie.description')
            ->set('saisie.jour', now()->subDay()->toDateString())->set('saisie.description', 'Harira et dattes')->call('enregistrer')->assertHasErrors('saisie.jour')
            ->set('saisie.jour', $demain)->call('enregistrer')->assertHasNoErrors();

        $this->assertSame(2, Menu::where('statut', StatutMenu::Propose->value)->whereDate('date_repas', $demain)->count());
        $menu = Menu::where('internat_id', $this->filles->id)->sole();

        // Doublon jour / repas / internat : refusé, rien n'est créé.
        Livewire::actingAs($this->restauration)->test(Menus::class)->call('proposer', $demain, TypeRepas::Diner->value)
            ->set('saisie.internats', [(string) $this->filles->id])->set('saisie.description', 'Doublon')->call('enregistrer');
        $this->assertSame(2, Menu::count());

        // Un internat hors périmètre ne se glisse pas dans la proposition.
        Livewire::actingAs($this->restauration)->test(Menus::class)->call('proposer', $demain, TypeRepas::Dejeuner->value)
            ->set('saisie.internats', [(string) $this->ailleurs->id])->set('saisie.description', 'Intrus')->call('enregistrer')->assertForbidden();
        $this->assertSame(0, Menu::where('internat_id', $this->ailleurs->id)->count());

        $ecran = Livewire::actingAs($this->restauration)->test(Menus::class);
        $ecran->call('modifier', $menu->id)->assertSet('saisie.description', 'Harira et dattes')->set('saisie.description', 'Harira, dattes et œufs')->call('enregistrer')->assertHasNoErrors();
        $this->assertSame('Harira, dattes et œufs', $menu->fresh()->description);

        $ecran->call('valider', $menu->id)->assertHasNoErrors();
        $this->assertSame([StatutMenu::Valide, $this->restauration->id], [$menu->fresh()->statut, $menu->fresh()->valide_par]);

        // Validé : ni modification, ni suppression.
        $ecran->call('modifier', $menu->id)->set('saisie.description', 'Autre plat')->call('enregistrer');
        $this->assertSame('Harira, dattes et œufs', $menu->fresh()->description);
        $ecran->call('supprimer', $menu->id);
        $this->assertNotNull($menu->fresh());

        // Un menu seulement proposé se supprime.
        $autre = Menu::where('internat_id', $this->garcons->id)->sole();
        $ecran->call('supprimer', $autre->id)->assertHasNoErrors();
        $this->assertNull($autre->fresh());
    }

    public function test_la_carte_se_consulte_sans_se_gerer_et_reste_dans_le_perimetre(): void
    {
        $menu = $this->menu(now()->addDay()->toDateString(), valide: false);

        // Le chef de pôle consulte ; il ne propose, ne valide ni ne supprime.
        $lecture = Livewire::actingAs($this->chefDePole)->test(Menus::class)->assertSee('Tajine de légumes')->assertDontSee('Proposer un menu');
        $lecture->call('proposer')->assertForbidden();
        Livewire::actingAs($this->chefDePole)->test(Menus::class)->call('valider', $menu->id)->assertForbidden();
        Livewire::actingAs($this->chefDePole)->test(Menus::class)->call('supprimer', $menu->id)->assertForbidden();
        Livewire::actingAs($this->chefDePole)->test(ServiceDesRepas::class)->call('preparerEffectif', $menu->id)->assertForbidden();

        // Le gestionnaire n'a pas accès à la restauration ; un autre établissement ne voit pas ce menu.
        $this->connecter($this->gestionnaireFilles)->get('/restauration/menus')->assertForbidden();
        $this->connecter($this->restauration)->get('/restauration/menus')->assertOk()->assertSee('Tajine de légumes');
        Livewire::actingAs($this->restauration, 'web')->test(Menus::class)->set('internat', (string) $this->ailleurs->id)->assertForbidden();
        Livewire::actingAs($this->restauration, 'web')->test(Menus::class)->call('decaler', 1)->assertSet('semaine', 1)->call('cetteSemaine')->assertSet('semaine', 0);
    }

    public function test_l_effectif_confirme_fige_les_reservations_et_l_ecart_au_service_se_voit(): void
    {
        $lits = $this->creerLits($this->filles, 2, 2);
        $residentes = collect([$this->creerResident($this->campagne, $lits[0]), $this->creerResident($this->campagne, $lits[1])]);
        Paiement::query()->update(['statut' => StatutPaiement::Valide->value]);

        $menu = $this->menu(now()->toDateString());
        $reservations = $residentes->map(fn ($affectation) => ReservationRepas::create([
            'menu_id' => $menu->id, 'stagiaire_id' => $affectation->demande->stagiaire_id, 'statut' => StatutReservation::Reserve, 'date_reservation' => now()->subDay(),
        ]));
        RegimeAlimentaire::create(['stagiaire_id' => $residentes[0]->demande->stagiaire_id, 'type' => TypeRegime::Allergie, 'precision_regime' => 'Arachides']);

        $ecran = Livewire::actingAs($this->restauration)->test(ServiceDesRepas::class)->assertSee('Effectif à confirmer')->assertSee('Tajine de légumes');

        // Le service ne se clôt pas avant la confirmation de l'effectif.
        $ecran->call('preparerCloture', $menu->id)->call('clore');
        $this->assertNull(ServiceRepas::where('menu_id', $menu->id)->value('date_cloture_service'));
        $ecran->call('fermerDialogue');

        $ecran->call('preparerEffectif', $menu->id)->assertSet('effectif', '2')->set('effectif', '')->call('confirmer')->assertHasErrors('effectif')
            ->set('effectif', '2')->call('confirmer')->assertHasNoErrors()->assertSee('Effectif confirmé');
        $service = ServiceRepas::where('menu_id', $menu->id)->sole();
        $this->assertSame([2, 2], [$service->effectif_reserve, $service->effectif_confirme]);

        // Confirmé deux fois : refusé.
        $ecran->call('preparerEffectif', $menu->id)->set('effectif', '9')->call('confirmer');
        $this->assertSame(2, $service->fresh()->effectif_confirme);
        $ecran->call('fermerDialogue');

        // Pointage : une absente. Le régime particulier est rappelé ; l'écart rend le service non conforme.
        $ecran->call('preparerCloture', $menu->id)->assertSee('Arachides')
            ->assertSet('pointage', [$reservations[0]->id => 'servi', $reservations[1]->id => 'servi'])
            ->set('pointage.'.$reservations[1]->id, 'absent')->set('observation', 'Une résidente en sortie.')->call('clore')->assertHasNoErrors()
            ->assertSee('Écart constaté')->assertSee('Une résidente en sortie.');

        $service->refresh();
        $this->assertSame([1, false], [$service->effectif_servi, $service->conforme]);
        $this->assertSame(StatutReservation::Servi, $reservations[0]->fresh()->statut);
        $this->assertSame(StatutReservation::Absent, $reservations[1]->fresh()->statut);

        // Clos : on ne le clôt pas une seconde fois.
        $ecran->call('preparerCloture', $menu->id)->call('clore');
        $this->assertSame(1, $service->fresh()->effectif_servi);
    }

    public function test_un_service_sans_reservation_nominative_se_clot_par_un_nombre(): void
    {
        $menu = $this->menu(now()->toDateString());
        $demain = $this->menu(now()->addDay()->toDateString());

        $ecran = Livewire::actingAs($this->restauration)->test(ServiceDesRepas::class);
        $ecran->call('preparerEffectif', $menu->id)->set('effectif', '12')->call('confirmer')->assertHasNoErrors();
        $ecran->call('preparerCloture', $menu->id)->assertSet('pointage', [])->call('clore')->assertHasErrors('servis')
            ->set('servis', '12')->call('clore')->assertHasNoErrors()->assertSee('Service conforme');
        $this->assertTrue(ServiceRepas::where('menu_id', $menu->id)->sole()->conforme);

        // Le service de demain se confirme, mais ne se clôt que le jour du repas.
        $ecran->call('choisir', 'demain')->assertSet('jour', now()->addDay()->toDateString())->assertSee('Effectif à confirmer');
        $ecran->call('preparerEffectif', $demain->id)->set('effectif', '8')->call('confirmer')->assertHasNoErrors()->assertSee('Le service se clôt le jour du repas.');
        $ecran->call('preparerCloture', $demain->id)->set('servis', '8')->call('clore');
        $this->assertNull(ServiceRepas::where('menu_id', $demain->id)->sole()->date_cloture_service);
    }

    public function test_les_statistiques_du_mois_et_les_regimes_restent_dans_le_perimetre(): void
    {
        $lits = $this->creerLits($this->filles, 1, 2);
        $residente = $this->creerResident($this->campagne, $lits[0]);
        $menu = $this->menu(now()->toDateString());
        $this->agirEnTantQue($this->restauration);
        app(Restauration::class)->confirmerEffectif($menu, 10, $this->restauration);
        app(Restauration::class)->cloturerService($menu, [], 9, null, $this->restauration);

        Storage::disk('prive')->put('regimes/1/certificat.pdf', '%PDF-1.4 certificat');
        $regime = RegimeAlimentaire::create([
            'stagiaire_id' => $residente->demande->stagiaire_id, 'type' => TypeRegime::RestrictionMedicale,
            'precision_regime' => 'Sans gluten', 'justificatif_fichier' => 'regimes/1/certificat.pdf',
        ]);

        $ecran = Livewire::actingAs($this->restauration)->test(StatistiquesRepas::class)->assertSet('mois', now()->format('Y-m'))
            ->assertSee('Repas servis')->assertSee('0 / 1')->assertSee('Restriction médicale : 1')->assertSee('Sans gluten')->assertSee($residente->demande->stagiaire->nom);

        $ecran->call('voirJustificatif', $regime->id)->assertSet('dialogue', 'justificatif');
        $ecran->call('fermerDialogue')->set('mois', '1999-01')->assertSee('Aucun menu validé sur ce mois');

        // Le chef de pôle voit les chiffres, pas le détail nominatif ni les justificatifs.
        Livewire::actingAs($this->chefDePole)->test(StatistiquesRepas::class)->assertSee('Restriction médicale : 1')->assertDontSee('Sans gluten')
            ->call('voirJustificatif', $regime->id)->assertForbidden();

        $this->connecter($this->restauration)->get(route('restauration.regimes.justificatif', $regime))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue(JournalAudit::where('action', 'regime.consultation_justificatif')->exists());
        $this->connecter($this->chefDePole)->get(route('restauration.regimes.justificatif', $regime))->assertForbidden();

        // Un responsable d'un autre établissement ne lit ni le régime ni son justificatif.
        $autre = \App\Models\Utilisateur::factory()->restauration($this->ailleurs->etablissement_id)->create();
        $this->connecter($autre)->get(route('restauration.regimes.justificatif', $regime))->assertNotFound();
        Livewire::actingAs($autre, 'web')->test(StatistiquesRepas::class)->assertDontSee('Restriction médicale : 1')->call('voirJustificatif', $regime->id)->assertNotFound();
    }
}
