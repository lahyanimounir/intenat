<?php

namespace App\Livewire\Personnel;

use App\Domain\Catering\AbsencesRepas;
use App\Domain\Catering\Restauration;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Livewire\PagePersonnel;
use App\Models\Menu;
use App\Models\ReservationRepas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Service et effectifs d'un jour. Pour chaque repas validé : ce que les
 * stagiaires ont réservé, ce que le responsable confirme la veille (J+1), et ce
 * qui est réellement servi. Un écart entre confirmé et servi marque le service
 * « non conforme ».
 */
#[Title('Service et effectifs')]
class ServiceDesRepas extends PagePersonnel
{
    private const CONSULTER = 'restauration.consulter';

    private const GERER = 'restauration.gerer';

    #[Url(except: '')]
    public string $jour = '';

    #[Url(except: '')]
    public string $internat = '';

    #[Locked]
    public ?int $ligne = null;

    public string $effectif = '';

    /** Pointage nominatif : réservation => « servi » ou « absent ». */
    public array $pointage = [];

    /** Nombre de repas servis, quand aucune réservation nominative n'est à pointer. */
    public string $servis = '';

    public string $observation = '';

    public function mount(): void
    {
        $this->exiger(self::CONSULTER);
        // Le jour affiché est toujours explicite : le champ de date et l'adresse le reflètent.
        $this->jour = $this->date()->toDateString();
    }

    public function choisir(string $jour): void
    {
        $this->jour = ($jour === 'demain' ? now()->addDay() : now())->toDateString();
    }

    private function date(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->jour ?: now())->startOfDay(), now()->startOfDay(), false);
    }

    private function menu(int $id): Menu
    {
        $menu = Menu::findOrFail($id);
        $this->autoriser($menu, self::GERER);

        return $menu;
    }

    /** @return Collection<int, ReservationRepas> réservations encore à pointer */
    private function aPointer(Menu $menu): Collection
    {
        return ReservationRepas::where('menu_id', $menu->id)->where('statut', StatutReservation::Reserve->value)
            ->with('stagiaire:id,nom,prenom,cef', 'stagiaire.regimes')->get()
            ->sortBy(fn (ReservationRepas $r) => $r->stagiaire->nom.' '.$r->stagiaire->prenom)->values();
    }

    /* ------------------------------------------------------------- Absents */

    /** Liste nominative des absents d'un repas : consultable par qui suit la restauration. */
    public function voirAbsents(int $id): void
    {
        $menu = Menu::findOrFail($id);
        $this->autoriser($menu, self::CONSULTER);
        $this->ligne = $menu->id;
        $this->ouvrirDialogue('absents');
    }

    /* ----------------------------------------------- Effectif prévisionnel (J+1) */

    public function preparerEffectif(Restauration $restauration, int $id): void
    {
        $menu = $this->menu($id);
        $this->ligne = $menu->id;
        $this->effectif = (string) $restauration->effectifReserve($menu->id);
        $this->ouvrirDialogue('effectif');
    }

    public function confirmer(Restauration $restauration): void
    {
        $menu = $this->menu((int) $this->ligne);
        $effectif = $this->validate(['effectif' => ['required', 'integer', 'min:0', 'max:10000']], attributes: ['effectif' => 'effectif confirmé'])['effectif'];

        if ($this->executer(fn () => $restauration->confirmerEffectif($menu, (int) $effectif, $this->utilisateur()), 'Effectif confirmé : les réservations de ce repas sont figées.')) {
            $this->fermerDialogue();
        }
    }

    /* ------------------------------------------------------- Clôture du service */

    public function preparerCloture(int $id): void
    {
        $menu = $this->menu($id);
        $this->ligne = $menu->id;
        // Par défaut, chaque réservation est réputée servie : on ne signale que les absents.
        $this->pointage = $this->aPointer($menu)->mapWithKeys(fn (ReservationRepas $r) => [$r->id => 'servi'])->all();
        $this->servis = '';
        $this->observation = '';
        $this->ouvrirDialogue('cloture');
    }

    public function clore(Restauration $restauration): void
    {
        $menu = $this->menu((int) $this->ligne);
        $attendues = $this->aPointer($menu)->pluck('id')->all();

        $valides = $this->validate([
            'pointage' => ['array'],
            'pointage.*' => ['in:servi,absent'],
            'servis' => [$attendues === [] ? 'required' : 'nullable', 'integer', 'min:0', 'max:10000'],
            'observation' => ['nullable', 'string', 'max:500'],
        ], attributes: ['servis' => 'nombre de repas servis']);

        // Toute réservation non pointée explicitement reste « servie » ; un identifiant étranger au repas est écarté par le domaine.
        $pointage = collect($attendues)->mapWithKeys(fn (int $id) => [$id => ($valides['pointage'][$id] ?? 'servi')])->all();

        if ($this->executer(fn () => $restauration->cloturerService(
            $menu, $pointage, $attendues === [] ? (int) $valides['servis'] : null, trim((string) $valides['observation']) ?: null, $this->utilisateur(),
        ), 'Service clos.')) {
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->reset('pointage', 'servis', 'observation', 'effectif');
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::CONSULTER);

        if ($this->internat !== '') {
            $this->perimetre()->exigerInternat($this->utilisateur(), (int) $this->internat);
        }

        $date = $this->date();

        $menus = Menu::visiblesPar($this->utilisateur())->whereDate('date_repas', $date)->where('statut', StatutMenu::Valide->value)
            ->when($this->internat !== '', fn (Builder $q) => $q->where('internat_id', (int) $this->internat))
            ->with('internat:id,nom', 'service')
            ->withCount(['reservations as reserves' => fn (Builder $q) => $q->where('statut', '!=', StatutReservation::Annule->value)])
            ->orderBy('internat_id')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")->get();

        $choisi = $this->ligne !== null ? $menus->firstWhere('id', $this->ligne) : null;

        return view('livewire.personnel.service-des-repas', [
            'date' => $date,
            'menus' => $menus,
            'futur' => $date->greaterThan(now()->startOfDay()),
            'internats' => $this->internatsDuPerimetre(),
            'gerer' => $this->peut(self::GERER),
            'choisi' => $choisi,
            'reservations' => $choisi && in_array($this->dialogue, ['cloture', 'effectif'], true) ? $this->aPointer($choisi) : collect(),
            'absents' => $choisi && $this->dialogue === 'absents'
                ? collect(app(AbsencesRepas::class)->duJour($this->utilisateur(), $date, menuId: $choisi->id)->first()?->repas ?? [])->flatMap->absents->values()
                : collect(),
            'impressionJournee' => route('restauration.absences.imprimer', array_filter(['jour' => $date->toDateString(), 'internat' => $this->internat])),
        ]);
    }
}
