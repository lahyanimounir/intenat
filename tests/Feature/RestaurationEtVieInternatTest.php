<?php

namespace Tests\Feature;

use App\Domain\Catering\CarteDesMenus;
use App\Domain\Catering\Restauration;
use App\Domain\Catering\StatistiquesRestauration;
use App\Domain\Residence\VieInternat;
use App\Enums\DomaineReclamation;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutReclamation;
use App\Enums\StatutReservation;
use App\Enums\StatutSortie;
use App\Enums\TypeRepas;
use App\Livewire\Personnel\Menus;
use App\Livewire\Personnel\Presences;
use App\Livewire\Personnel\Reclamations;
use App\Livewire\Personnel\ServiceDesRepas;
use App\Livewire\Stagiaire\Reclamations as MesReclamations;
use App\Livewire\Stagiaire\Repas;
use App\Livewire\Stagiaire\Sorties;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\Campagne;
use App\Models\DemandeSortie;
use App\Models\Menu;
use App\Models\NotificationInterne;
use App\Models\Paiement;
use App\Models\Reclamation;
use App\Models\ReservationRepas;
use App\Models\ServiceRepas;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Restauration (menus, réservations, effectifs J+1, service) et vie
 * quotidienne (sorties, absences, réclamations).
 */
class RestaurationEtVieInternatTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Campagne $campagne;

    /** @var Collection<int, Affectation> */
    private Collection $residentes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
        $this->campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10]);
        $lits = $this->creerLits($this->filles, 2, 2);
        $this->residentes = collect([$this->creerResident($this->campagne, $lits[0]), $this->creerResident($this->campagne, $lits[1])]);

        // Reçus validés : ces résidentes ne sont pas concernées par le blocage du 5 (testé à part).
        Paiement::query()->update(['statut' => StatutPaiement::Valide->value]);
    }

    private function menu(string $date, TypeRepas $repas = TypeRepas::Dejeuner, bool $valide = true): Menu
    {
        $this->agirEnTantQue($this->restauration);
        $menu = app(CarteDesMenus::class)->proposer([$this->filles->id], now()->parse($date), $repas, 'Tajine de légumes', $this->restauration)[0];

        return $valide ? app(Restauration::class)->validerMenu($menu, $this->restauration) : $menu;
    }

    private function reserver(Stagiaire $stagiaire, Menu $menu): ReservationRepas
    {
        $this->agirEnTantQue($stagiaire);

        return app(Restauration::class)->reserver($stagiaire, $menu);
    }

    public function test_seul_un_menu_valide_est_propose_aux_stagiaires(): void
    {
        $jour = now()->addDays(3)->toDateString();
        $propose = $this->menu($jour, TypeRepas::Diner, false);
        $valide = $this->menu($jour);
        $residente = $this->residentes[0]->demande->stagiaire;

        // La carte du stagiaire ne montre que le menu validé ; le menu seulement proposé ne se réserve pas.
        $this->assertSame([$valide->id], Menu::where('internat_id', $this->filles->id)->where('statut', StatutMenu::Valide->value)->pluck('id')->all());
        $this->assertNull(app(Restauration::class)->motifIneligibilite($residente, $valide));
        $this->assertRegle('reservation_impossible', fn () => $this->reserver($residente, $propose));
        $this->ecran($residente, Repas::class)->assertSee('Tajine de légumes')->call('reserver', $propose->id);
        $this->assertSame(0, ReservationRepas::count());

        // Un menu validé ne se modifie plus ; un doublon jour / repas / internat est refusé.
        $this->agirEnTantQue($this->restauration);
        $this->assertRegle('menu_valide', fn () => app(CarteDesMenus::class)->modifier($valide->fresh(), 'Autre plat'));
        $refus = $this->assertRegle('menu_existant', fn () => app(CarteDesMenus::class)->proposer([$this->filles->id], now()->parse($jour), TypeRepas::Dejeuner, 'Doublon', $this->restauration));
        $this->assertSame(409, $refus->statutHttp);
    }

    public function test_la_reservation_est_reservee_aux_residents_de_l_internat_et_bornee_par_l_heure_limite(): void
    {
        $menu = $this->menu(now()->addDays(2)->toDateString());
        [$residente, $autre] = [$this->residentes[0]->demande->stagiaire, $this->residentes[1]->demande->stagiaire];

        // Une stagiaire non hébergée ne peut pas réserver.
        $this->assertRegle('reservation_impossible', fn () => $this->reserver($this->creerStagiaire($this->filles), $menu));

        // Réserver deux fois ne crée qu'une réservation.
        $this->ecran($residente, Repas::class)->call('reserver', $menu->id)->assertHasNoErrors()->call('reserver', $menu->id);
        $this->assertSame(1, ReservationRepas::count());
        $reservation = ReservationRepas::firstOrFail();

        // La veille après 18 h : ni réservation ni annulation.
        $this->travelTo(now()->addDay()->setTime(18, 30));
        $this->agirEnTantQue($residente);
        $this->assertRegle('annulation_impossible', fn () => app(Restauration::class)->annuler($reservation->fresh()));
        $this->assertRegle('reservation_impossible', fn () => $this->reserver($autre, $menu));

        // Une autre stagiaire ne peut pas annuler la réservation d'autrui.
        $this->travelBack();
        $this->ecran($autre, Repas::class)->call('annuler', $reservation->id)->assertNotFound();
        $this->assertSame(StatutReservation::Reserve, $reservation->fresh()->statut);
        $this->ecran($residente, Repas::class)->call('annuler', $reservation->id)->assertHasNoErrors();
        $this->assertSame(StatutReservation::Annule, $reservation->fresh()->statut);
    }

    public function test_reserve_confirme_et_servi_sont_suivis_separement(): void
    {
        $menu = $this->menu(now()->addDay()->toDateString());
        $restauration = app(Restauration::class);

        // Réservations enregistrées avant l'heure limite de la veille.
        $this->travelTo(now()->setTime(9, 0));
        $reservations = $this->residentes->map(fn (Affectation $a) => $this->reserver($a->demande->stagiaire, $menu));

        // J+1 : le responsable confirme l'effectif prévisionnel ; les réservations sont alors figées.
        $this->agirEnTantQue($this->restauration);
        $this->assertRegle('effectif_non_confirme', fn () => $restauration->cloturerService($menu, [], 2, null, $this->restauration));

        $this->ecran($this->restauration, ServiceDesRepas::class)->call('choisir', 'demain')->call('preparerEffectif', $menu->id)->assertSet('effectif', '2')
            ->set('effectif', '3')->call('confirmer')->assertHasNoErrors();
        $service = ServiceRepas::where('menu_id', $menu->id)->firstOrFail();
        $this->assertSame([2, 3], [$service->effectif_reserve, $service->effectif_confirme]);

        $this->agirEnTantQue($this->restauration);
        $this->assertSame(409, $this->assertRegle('deja_confirme', fn () => $restauration->confirmerEffectif($menu, null, $this->restauration))->statutHttp);

        $this->agirEnTantQue($this->residentes[0]->demande->stagiaire);
        $this->assertRegle('annulation_impossible', fn () => $restauration->annuler($reservations[0]->fresh()));

        // Le service ne se clôt pas avant le jour du repas.
        $this->agirEnTantQue($this->restauration);
        $this->assertRegle('repas_futur', fn () => $restauration->cloturerService($menu, [], 2, null, $this->restauration));

        // Jour du repas : pointage nominatif, une absente. Servi (1) ≠ confirmé (3) : non conforme.
        $this->travelTo(now()->addDay()->setTime(14, 0));
        $this->ecran($this->restauration, ServiceDesRepas::class)->call('preparerCloture', $menu->id)
            ->set('pointage.'.$reservations[1]->id, 'absent')->set('observation', 'Une absente.')->call('clore')->assertHasNoErrors();

        $service->refresh();
        $this->assertSame([2, 3, 1, false], [$service->effectif_reserve, $service->effectif_confirme, $service->effectif_servi, $service->conforme]);
        $this->assertSame([StatutReservation::Servi, StatutReservation::Absent], [$reservations[0]->fresh()->statut, $reservations[1]->fresh()->statut]);

        $this->agirEnTantQue($this->restauration);
        $this->assertSame(409, $this->assertRegle('service_clos', fn () => $restauration->cloturerService($menu, [], 2, null, $this->restauration))->statutHttp);

        // Statistiques du mois issues des mêmes lignes.
        $total = app(StatistiquesRestauration::class)->duMois($this->restauration, $menu->date_repas->format('Y-m'))['total'];
        $this->assertSame([2, 3, 1, 0], [$total['reserves'], $total['confirmes'], $total['servis'], $total['conformes']]);
    }

    public function test_le_responsable_restauration_ne_gere_que_les_menus_de_son_etablissement(): void
    {
        $menu = $this->menu(now()->addDays(2)->toDateString());

        // Proposer un menu dans un internat d'un autre établissement est refusé.
        $this->ecran($this->restauration, Menus::class)->call('proposer', now()->addDays(2)->toDateString(), 'diner')
            ->set('saisie.internats', [(string) $this->ailleurs->id])->set('saisie.description', 'Hors périmètre')->call('enregistrer')->assertForbidden();
        $this->assertSame(1, Menu::count());

        // Le responsable d'un autre établissement ne voit ni ne touche ce menu.
        $ailleurs = Utilisateur::factory()->restauration($this->ailleurs->etablissement_id)->create();
        $this->ecran($ailleurs, Menus::class)->assertDontSee('Tajine de légumes')->call('decaler', 1)->assertDontSee('Tajine de légumes');
        $this->ecran($ailleurs, ServiceDesRepas::class)->call('preparerEffectif', $menu->id)->assertForbidden();
        $this->ecran($ailleurs, ServiceDesRepas::class)->call('preparerCloture', $menu->id)->assertForbidden();
        $this->ecran($ailleurs, Menus::class)->call('modifier', $menu->id)->assertForbidden();

        // Le gestionnaire n'a pas la main sur la restauration.
        $this->ecran($this->gestionnaireFilles, Menus::class)->assertForbidden();
        $this->connecter($this->gestionnaireFilles)->get('/restauration/service')->assertForbidden();
    }

    public function test_une_absence_non_couverte_par_une_sortie_acceptee_declenche_une_alerte(): void
    {
        [$a, $b] = [$this->residentes[0], $this->residentes[1]];

        // La résidente A demande une sortie, que le gestionnaire accepte.
        $this->ecran($a->demande->stagiaire, Sorties::class)
            ->set('date_depart', now()->addHour()->format('Y-m-d\TH:i'))->set('date_retour', now()->addDays(2)->format('Y-m-d\TH:i'))->set('motif', 'Retour en famille.')
            ->call('deposer')->assertHasNoErrors();
        $sortie = DemandeSortie::sole();

        $this->ecran($this->gestionnaireGarcons, Presences::class)->assertDontSee('Retour en famille.');
        $ecran = $this->ecran($this->gestionnaireFilles, Presences::class)->assertSee('Retour en famille.');
        // Un refus exige un motif.
        $ecran->call('refuser', $sortie->id)->assertHasErrors('motif');
        $this->assertSame(StatutSortie::EnAttente, $sortie->fresh()->statut);
        $ecran->call('accepter', $sortie->id)->assertHasNoErrors();
        $this->assertSame(StatutSortie::Acceptee, $sortie->fresh()->statut);

        // Le lendemain, les deux sont absentes : A est couverte, B non.
        $this->travelTo(now()->addDay()->setTime(22, 0));
        $this->ecran($this->gestionnaireFilles, Presences::class)->call('voir', 'feuille')
            ->assertSee($a->demande->stagiaire->cef)->assertSee($b->demande->stagiaire->cef)
            ->set('absents', [(string) $a->id, (string) $b->id])->call('saisir')->assertHasNoErrors();

        $this->assertTrue((bool) Absence::where('affectation_id', $a->id)->value('autorisee'));
        $this->assertSame($sortie->id, Absence::where('affectation_id', $a->id)->value('demande_sortie_id'));
        $this->assertFalse((bool) Absence::where('affectation_id', $b->id)->value('autorisee'));

        // Alerte dès la première journée, au gestionnaire seul, sans doublon si la saisie est rejouée.
        $alertes = fn ($compte) => NotificationInterne::where('utilisateur_id', $compte->id)->where('type', 'absence_non_autorisee')->count();
        $this->assertSame([1, 0, 0], [$alertes($this->gestionnaireFilles), $alertes($this->chefDePole), $alertes($this->gestionnaireGarcons)]);

        $this->agirEnTantQue($this->gestionnaireFilles);
        $bilan = app(VieInternat::class)->saisirAbsences($this->filles->id, now()->startOfDay(), [$a->id, $b->id], $this->gestionnaireFilles);
        $this->assertSame(0, $bilan['creees']);
        $this->assertSame(1, $alertes($this->gestionnaireFilles));

        // Un résident d'un autre internat ne peut pas être pointé depuis cet internat.
        $garcon = $this->creerResident($this->creerCampagne($this->garcons), $this->creerLits($this->garcons, 1, 1)[0]);
        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertRegle('resident_hors_internat', fn () => app(VieInternat::class)->saisirAbsences($this->filles->id, now()->startOfDay(), [$garcon->id], $this->gestionnaireFilles));
        $this->assertRegle('date_future', fn () => app(VieInternat::class)->saisirAbsences($this->filles->id, now()->addDay(), [$a->id], $this->gestionnaireFilles));
    }

    public function test_une_reclamation_est_orientee_vers_le_responsable_de_son_domaine(): void
    {
        $stagiaire = $this->residentes[0]->demande->stagiaire;
        $textes = ['hebergement' => 'Chauffage en panne dans la chambre.', 'restauration' => 'Portions insuffisantes au dîner.', 'selection' => 'Je conteste mon classement initial.'];

        foreach ($textes as $domaine => $texte) {
            $this->ecran($stagiaire, MesReclamations::class)->set('domaine', $domaine)->set('description', $texte)->call('deposer')->assertHasNoErrors();
        }

        $this->assertSame(3, Reclamation::count());

        // Chaque responsable ne voit que les réclamations de son domaine, dans son périmètre.
        foreach ([[$this->gestionnaireFilles, 'hebergement'], [$this->restauration, 'restauration'], [$this->chefDePole, 'selection']] as [$compte, $sien]) {
            $ecran = $this->ecran($compte, Reclamations::class);

            foreach ($textes as $domaine => $texte) {
                $domaine === $sien ? $ecran->assertSee($texte) : $ecran->assertDontSee($texte);
            }
        }

        $this->ecran($this->gestionnaireGarcons, Reclamations::class)->assertDontSee($textes['hebergement']);

        // Chacun ne traite que son domaine ; clore exige une réponse.
        $restauration = Reclamation::where('domaine', DomaineReclamation::Restauration->value)->firstOrFail();
        $this->ecran($this->gestionnaireFilles, Reclamations::class)->call('repondre', $restauration->id)->assertForbidden();

        $this->agirEnTantQue($this->restauration);
        $this->assertRegle('reponse_requise', fn () => app(VieInternat::class)->traiterReclamation($restauration->fresh(), StatutReclamation::Traitee, null, $this->restauration));

        $this->ecran($this->restauration, Reclamations::class)->call('repondre', $restauration->id)->call('clore')->assertHasErrors('reponse')
            ->set('reponse', 'Les portions sont revues à compter de lundi.')->call('clore')->assertHasNoErrors();
        $this->assertSame(StatutReclamation::Traitee, $restauration->fresh()->statut);

        // Le stagiaire lit la réponse dans son espace.
        $this->ecran($stagiaire, MesReclamations::class)->assertSee('Les portions sont revues à compter de lundi.');
    }
}
