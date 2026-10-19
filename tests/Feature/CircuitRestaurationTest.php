<?php

namespace Tests\Feature;

use App\Domain\Catering\CarteDesMenus;
use App\Domain\Catering\ListeDesRepas;
use App\Domain\Catering\Restauration;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use App\Livewire\Personnel\ListeRepasJ1;
use App\Livewire\Personnel\Menus;
use App\Livewire\Personnel\ServiceDesRepas;
use App\Livewire\Stagiaire\Repas;
use App\Models\Affectation;
use App\Models\Campagne;
use App\Models\JournalAudit;
use App\Models\Menu;
use App\Models\NotificationInterne;
use App\Models\Paiement;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Circuit complet de la restauration : menus de la semaine composés par le
 * responsable restauration, transmis au chef de pôle qui les valide (ou les
 * renvoie), réservation J+1 ou de la semaine avec allergie par repas, régimes,
 * confirmation des effectifs et liste nominative J+1 imprimable.
 */
class CircuitRestaurationTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Campagne $campagne;

    /** @var Collection<int, Affectation> */
    private Collection $residentes;

    private Carbon $lundi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        // Un lundi matin : toute la semaine est encore réservable.
        $this->lundi = now()->next(Carbon::MONDAY)->setTime(9, 0);
        $this->travelTo($this->lundi);
        $this->creerMonde();
        $this->campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10]);
        $lits = $this->creerLits($this->filles, 2, 2);
        $this->residentes = collect([$this->creerResident($this->campagne, $lits[0]), $this->creerResident($this->campagne, $lits[1])]);
        Paiement::query()->update(['statut' => StatutPaiement::Valide->value]);
    }

    /** Propose les trois repas d'un jour de la semaine (0 = lundi). */
    private function proposerJour(int $jour): Collection
    {
        $this->agirEnTantQue($this->restauration);

        return collect(TypeRepas::cases())->map(fn (TypeRepas $repas) => app(CarteDesMenus::class)
            ->proposer([$this->filles->id], $this->lundi->copy()->addDays($jour), $repas, 'Plat '.$repas->libelle().' '.$jour, $this->restauration)[0]);
    }

    private function semaineValidee(): Collection
    {
        $menus = collect([1, 2, 3])->flatMap(fn (int $j) => $this->proposerJour($j));
        $this->ecran($this->restauration, Menus::class)->call('transmettreSemaine');
        $this->ecran($this->chefDePole, Menus::class)->call('validerSemaine');

        return $menus->map->fresh();
    }

    public function test_la_semaine_est_transmise_au_chef_de_pole_qui_la_valide(): void
    {
        $menus = collect([1, 2])->flatMap(fn (int $j) => $this->proposerJour($j));
        $residente = $this->residentes[0]->demande->stagiaire;

        // Avant transmission : le chef de pôle ne peut pas valider, la restauration non plus (permission retirée).
        $this->agirEnTantQue($this->chefDePole);
        $this->assertRegle('menu_non_transmis', fn () => app(Restauration::class)->validerMenu($menus[0], $this->chefDePole));
        $this->ecran($this->restauration, Menus::class)->call('valider', $menus[0]->id)->assertForbidden();
        $this->ecran($this->restauration, Menus::class)->call('validerSemaine')->assertForbidden();
        $this->ecran($this->chefDePole, Menus::class)->call('transmettreSemaine')->assertForbidden();

        // Transmission : six menus, notification du chef de pôle, statut affiché.
        $this->ecran($this->restauration, Menus::class)->assertSee('6 à transmettre')
            ->call('transmettreSemaine')->assertDispatched('message', ton: 'succes');
        $this->assertSame(6, Menu::whereNotNull('transmis_le')->count());
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('titre', 'Menus de la semaine à valider')->exists());
        $this->ecran($this->restauration, Menus::class)->call('transmettreSemaine')->assertDispatched('message', ton: 'danger');

        // Transmis mais non validé : toujours pas réservable par le stagiaire.
        $this->assertRegle('reservation_impossible', fn () => app(Restauration::class)->reserver($residente, $menus[0]->fresh()));
        $this->ecran($residente, Repas::class)->assertDontSee('Plat Déjeuner 1');

        // Le chef de pôle consulte la semaine puis la valide d'un coup ; la restauration en est prévenue.
        $this->ecran($this->chefDePole, Menus::class)->assertSee('6 en attente de validation')->assertSee('Plat Dîner 2')
            ->call('validerSemaine')->assertDispatched('message', ton: 'succes');
        $this->assertSame(6, Menu::where('statut', StatutMenu::Valide->value)->where('valide_par', $this->chefDePole->id)->count());
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->restauration->id)->where('titre', 'Menus de la semaine validés')->exists());
        $this->ecran($residente, Repas::class)->assertSee('Plat Déjeuner 1');

        // Historique des validations, visible des deux côtés.
        foreach ([$this->chefDePole, $this->restauration] as $compte) {
            $this->ecran($compte, Menus::class)->call('voirHistorique')
                ->assertSee('Semaine transmise au chef de pôle')->assertSee('Semaine validée')->assertSee($this->chefDePole->nomComplet());
        }
    }

    public function test_le_chef_de_pole_renvoie_la_semaine_et_une_modification_impose_une_nouvelle_transmission(): void
    {
        $menus = $this->proposerJour(2);
        $this->ecran($this->restauration, Menus::class)->call('transmettreSemaine');

        // Renvoi motivé : les menus repassent en préparation, la restauration est prévenue.
        $this->ecran($this->chefDePole, Menus::class)->call('renvoyerSemaine', 'non')->assertDispatched('message', ton: 'danger');
        $this->ecran($this->chefDePole, Menus::class)->call('renvoyerSemaine', 'Le dîner manque de légumes.')->assertDispatched('message', ton: 'succes');
        $this->assertSame(0, Menu::whereNotNull('transmis_le')->count());
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->restauration->id)->where('message', 'like', '%Le dîner manque de légumes.%')->exists());
        $this->ecran($this->chefDePole, Menus::class)->call('voirHistorique')->assertSee('Semaine renvoyée pour correction')->assertSee('Le dîner manque de légumes.');

        // Retransmise puis modifiée : le menu modifié doit être retransmis avant validation.
        $this->ecran($this->restauration, Menus::class)->call('transmettreSemaine');
        $this->ecran($this->restauration, Menus::class)->call('modifier', $menus[2]->id)->set('saisie.description', 'Dîner aux légumes')->call('enregistrer')->assertHasNoErrors();
        $this->assertNull($menus[2]->fresh()->transmis_le);
        $this->ecran($this->chefDePole, Menus::class)->call('validerSemaine');
        $this->assertSame(StatutMenu::Propose, $menus[2]->fresh()->statut);
        $this->assertSame(StatutMenu::Valide, $menus[0]->fresh()->statut);
    }

    public function test_le_stagiaire_reserve_demain_ou_la_semaine_avec_une_allergie_par_repas(): void
    {
        $menus = $this->semaineValidee();
        $residente = $this->residentes[0]->demande->stagiaire;

        // J+1 : les trois repas de demain, avec une allergie signalée.
        $ecran = $this->ecran($residente, Repas::class)->call('selectionner', 'demain');
        $this->assertCount(3, $ecran->get('selection'));
        $ecran->call('preparerSelection')->assertSet('dialogue', 'reservation')
            ->set('allergie', 'Arachides')->call('reserverSelection')->assertDispatched('message', ton: 'succes');
        $this->assertSame(3, ReservationRepas::where('stagiaire_id', $residente->id)->where('allergie', 'Arachides')->count());

        // Toute la semaine : les repas restants s'ajoutent, sans toucher aux réservations existantes.
        $ecran->call('selectionner', 'semaine');
        $this->assertCount(6, $ecran->get('selection'));
        $ecran->call('preparerSelection')->call('reserverSelection');
        $this->assertSame(9, ReservationRepas::where('stagiaire_id', $residente->id)->where('statut', StatutReservation::Reserve->value)->count());
        $this->assertSame(3, ReservationRepas::where('stagiaire_id', $residente->id)->whereNotNull('allergie')->count());

        // Un seul repas, avec sa propre restriction ; modification puis annulation.
        $autre = $this->residentes[1]->demande->stagiaire;
        $this->ecran($autre, Repas::class)->call('preparerReservation', $menus[4]->id)->set('allergie', 'Lactose')->call('reserverSelection');
        $reservation = ReservationRepas::where('stagiaire_id', $autre->id)->firstOrFail();
        $this->assertSame('Lactose', $reservation->allergie);
        $this->ecran($autre, Repas::class)->assertSee('Mes réservations')->assertSee('Allergie : Lactose')
            ->call('modifierAllergie', $reservation->id)->set('allergie', 'Lactose et gluten')->call('enregistrerAllergie')->assertHasNoErrors();
        $this->assertSame('Lactose et gluten', $reservation->fresh()->allergie);
        $this->ecran($residente, Repas::class)->call('modifierAllergie', $reservation->id)->assertNotFound();
        $this->ecran($autre, Repas::class)->call('annuler', $reservation->id);
        $this->assertSame(StatutReservation::Annule, $reservation->fresh()->statut);

        // Passé l'heure limite (la veille, 18 h), l'allergie ne se modifie plus.
        $demain = ReservationRepas::where('stagiaire_id', $residente->id)->where('menu_id', $menus[0]->id)->firstOrFail();
        $this->travelTo($this->lundi->copy()->setTime(18, 30));
        $this->agirEnTantQue($residente);
        $this->assertRegle('modification_impossible', fn () => app(Restauration::class)->modifierAllergie($demain, 'Autre'));
    }

    public function test_les_regimes_vegetarien_et_autre_se_declarent_sans_justificatif(): void
    {
        $residente = $this->residentes[0]->demande->stagiaire;
        $ecran = $this->ecran($residente, Repas::class);

        $ecran->set('type', TypeRegime::Vegetarien->value)->call('declarerRegime')->assertHasNoErrors();
        $ecran->set('type', TypeRegime::Autre->value)->call('declarerRegime')->assertHasErrors('precision');
        $ecran->set('type', TypeRegime::Autre->value)->set('precision', 'Sans fruits de mer')->call('declarerRegime')->assertHasNoErrors();
        $ecran->set('type', TypeRegime::RestrictionMedicale->value)->call('declarerRegime')->assertHasErrors('justificatif');

        $this->assertEqualsCanonicalizing(['vegetarien', 'autre'], RegimeAlimentaire::where('stagiaire_id', $residente->id)->pluck('type')->map->value->all());
    }

    public function test_la_liste_j1_et_la_confirmation_des_effectifs_portent_regimes_et_allergies(): void
    {
        $menus = $this->semaineValidee();
        [$avecAllergie, $sansRien] = [$this->residentes[0]->demande->stagiaire, $this->residentes[1]->demande->stagiaire];
        RegimeAlimentaire::create(['stagiaire_id' => $avecAllergie->id, 'type' => TypeRegime::Vegetarien, 'precision_regime' => null]);

        $this->agirEnTantQue($avecAllergie);
        app(Restauration::class)->reserver($avecAllergie, $menus[1], 'Arachides');
        app(Restauration::class)->reserver($avecAllergie, $menus[2]);
        $this->agirEnTantQue($sansRien);
        app(Restauration::class)->reserver($sansRien, $menus[1]);
        // Un repas du surlendemain n'apparaît pas dans la liste J+1.
        app(Restauration::class)->reserver($sansRien, $menus[4]);

        // Domaine : lignes J+1, régimes, mention « aucun régime », totaux par type de repas.
        $lignes = app(ListeDesRepas::class)->lignes($this->restauration, $this->lundi->copy()->addDay());
        $this->assertCount(3, $lignes);
        $totaux = app(ListeDesRepas::class)->totaux($lignes);
        $this->assertSame([0, 2, 1], array_column($totaux, 'total'));
        $this->assertSame(ListeDesRepas::AUCUN_REGIME, $lignes->firstWhere('cef', $sansRien->cef)->regimes);
        $this->assertTrue($lignes->firstWhere('allergie', 'Arachides')->attention);

        // Écran : liste filtrable par type de repas, lignes à surveiller.
        $ecran = $this->ecran($this->restauration, ListeRepasJ1::class)
            ->assertSet('jour', $this->lundi->copy()->addDay()->toDateString())
            ->assertSee($avecAllergie->cef)->assertSee('Arachides')->assertSee('Végétarien')->assertSee('Aucun régime déclaré')->assertSee('Établissement de test');
        $ecran->set('repas', TypeRepas::Diner->value)->assertViewHas('lignes', fn ($l) => $l->count() === 1);
        $ecran->set('repas', '')->set('attention', true)->assertViewHas('lignes', fn ($l) => $l->every(fn ($x) => $x->attention) && $l->count() === 2);
        $this->ecran($this->chefDePole, ListeRepasJ1::class)->assertSee($avecAllergie->cef);
        $this->ecran($this->gestionnaireAilleurs, ListeRepasJ1::class)->assertForbidden();

        // Impression PDF de la liste J+1.
        $this->actingAs($this->restauration, 'web')->get(route('restauration.liste-j1.imprimer', ['repas' => TypeRepas::Dejeuner->value]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue(JournalAudit::where('action', 'restauration.liste_j1_imprimee')->exists());
        $this->actingAs($this->restauration, 'web')->get(route('restauration.liste-j1.imprimer', ['internat' => $this->ailleurs->id]))->assertForbidden();

        // Confirmation de l'effectif : la liste nominative du repas, avec régimes et allergies, est présentée.
        $this->ecran($this->restauration, ServiceDesRepas::class)->call('choisir', 'demain')->call('preparerEffectif', $menus[1]->id)
            ->assertSet('effectif', '2')->assertSee('Stagiaires concernés')->assertSee('Allergie pour ce repas : Arachides')->assertSee('Aucun régime déclaré')
            ->call('confirmer')->assertHasNoErrors();
        $this->assertSame(2, $menus[1]->fresh()->service->effectif_confirme);
    }
}
