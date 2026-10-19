<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Catering\Restauration;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Livewire\PageStagiaire;
use App\Models\Menu;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Repas du résident : menus validés des quatorze prochains jours, réservation
 * d'un repas (J+1) ou de plusieurs jours et repas d'un coup, modification de
 * l'allergie signalée et annulation jusqu'à l'heure limite, récapitulatif de
 * ses réservations, et déclaration de ses régimes alimentaires. Les règles —
 * résidence, menu validé, heure limite — sont celles du service Restauration.
 */
#[Title('Repas')]
class Repas extends PageStagiaire
{
    use WithFileUploads;

    public string $type = '';

    public string $precision = '';

    /** @var TemporaryUploadedFile|null */
    public $justificatif = null;

    /** Repas cochés pour une réservation groupée (identifiants de menus). */
    public array $selection = [];

    /** Repas réservé seul depuis sa ligne, sans toucher aux cases cochées. */
    #[Locked]
    public ?int $seul = null;

    /** Allergie ou restriction signalée pour le ou les repas réservés. */
    public string $allergie = '';

    /** Réservation dont on modifie l'allergie. */
    #[Locked]
    public ?int $ligne = null;

    /* ------------------------------------------------------------ Réservation */

    public function reserver(Restauration $restauration, int $menu): void
    {
        $this->executer(fn () => $restauration->reserver($this->stagiaire(), Menu::findOrFail($menu)), 'Repas réservé.');
    }

    /** Ouvre la réservation d'un repas, avec la possibilité d'y signaler une allergie. */
    public function preparerReservation(int $menu): void
    {
        $this->seul = $menu;
        $this->allergie = '';
        $this->ouvrirDialogue('reservation');
    }

    /** Coche d'un coup les repas réservables : « demain », « semaine » (sept prochains jours) ou « aucun ». */
    public function selectionner(string $periode): void
    {
        if ($periode === 'aucun') {
            $this->selection = [];

            return;
        }

        $jusquau = ($periode === 'demain' ? now()->addDay() : now()->addDays(7))->toDateString();
        $depuis = $periode === 'demain' ? now()->addDay()->toDateString() : now()->toDateString();

        $this->selection = $this->lignes()->flatten(1)
            ->filter(fn (object $l) => $l->reservable && $l->menu->date_repas->toDateString() >= $depuis && $l->menu->date_repas->toDateString() <= $jusquau)
            ->map(fn (object $l) => (string) $l->menu->id)->values()->all();

        if ($this->selection === []) {
            $this->message($periode === 'demain' ? 'Aucun repas de demain n’est réservable.' : 'Aucun repas de la semaine n’est réservable.', 'alerte');
        }
    }

    public function preparerSelection(): void
    {
        if ($this->selection === []) {
            $this->message('Cochez au moins un repas à réserver.', 'alerte');

            return;
        }

        $this->seul = null;
        $this->allergie = '';
        $this->ouvrirDialogue('reservation');
    }

    /** @return list<int> repas visés par la réservation en cours */
    private function vises(): array
    {
        return $this->seul !== null ? [$this->seul] : array_map('intval', $this->selection);
    }

    /** Réserve les repas choisis, en y reportant l'allergie signalée. */
    public function reserverSelection(Restauration $restauration): void
    {
        $allergie = $this->validate(['allergie' => ['nullable', 'string', 'max:255']], attributes: ['allergie' => 'allergie ou restriction'])['allergie'];
        $stagiaire = $this->stagiaire();
        $menus = Menu::whereKey($this->vises())->get();

        if ($menus->isEmpty()) {
            $this->message('Cochez au moins un repas à réserver.', 'alerte');

            return;
        }

        $reussis = 0;
        $refus = [];

        foreach ($menus as $menu) {
            try {
                $restauration->reserver($stagiaire, $menu, $allergie);
                $reussis++;
            } catch (RegleMetierException $regle) {
                $refus[] = $menu->type_repas->libelle().' du '.$menu->date_repas->format('d/m').' : '.$regle->getMessage();
            }
        }

        if ($this->seul === null) {
            $this->selection = [];
        } else {
            $this->selection = array_values(array_diff($this->selection, [(string) $this->seul]));
        }
        $this->fermerDialogue();

        if ($refus === []) {
            $this->message($reussis > 1 ? "{$reussis} repas réservés." : 'Repas réservé.');
        } else {
            $this->message(($reussis > 0 ? "{$reussis} repas réservé(s). " : '').'Non réservé(s) — '.implode(' ; ', $refus), $reussis > 0 ? 'alerte' : 'danger');
        }
    }

    public function modifierAllergie(int $reservation): void
    {
        $ligne = ReservationRepas::where('stagiaire_id', $this->stagiaire()->id)->findOrFail($reservation);
        $this->ligne = $ligne->id;
        $this->allergie = (string) $ligne->allergie;
        $this->ouvrirDialogue('allergie');
    }

    public function enregistrerAllergie(Restauration $restauration): void
    {
        $ligne = ReservationRepas::where('stagiaire_id', $this->stagiaire()->id)->findOrFail((int) $this->ligne);
        $allergie = $this->validate(['allergie' => ['nullable', 'string', 'max:255']], attributes: ['allergie' => 'allergie ou restriction'])['allergie'];

        if ($this->executer(fn () => $restauration->modifierAllergie($ligne, $allergie), 'Réservation modifiée.')) {
            $this->fermerDialogue();
        }
    }

    public function annuler(Restauration $restauration, int $reservation): void
    {
        $ligne = ReservationRepas::where('stagiaire_id', $this->stagiaire()->id)->findOrFail($reservation);

        $this->executer(fn () => $restauration->annuler($ligne), 'Réservation annulée.');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->seul = null;
        $this->dialogue = null;
        $this->allergie = '';
        $this->resetValidation();
    }

    /* ------------------------------------------------------------------ Régimes */

    public function declarerRegime(Documents $documents): void
    {
        $stagiaire = $this->stagiaire();
        $exigeants = collect(TypeRegime::cases())->filter->exigeJustificatif()->map->value->implode(',');

        $valides = $this->validate([
            'type' => ['required', Rule::enum(TypeRegime::class), Rule::unique('regime_alimentaire', 'type')->where('stagiaire_id', $stagiaire->id)],
            'precision' => [$this->type === TypeRegime::Autre->value ? 'required' : 'nullable', 'string', 'max:255'],
            'justificatif' => ['required_if:type,'.$exigeants, 'nullable', 'file'],
        ], [
            'type.unique' => 'Ce régime est déjà déclaré.',
            'justificatif.required_if' => 'Un justificatif est requis pour une allergie ou une restriction médicale.',
            'precision.required' => 'Précisez la restriction.',
        ], ['type' => 'type de régime', 'precision' => 'précision', 'justificatif' => 'justificatif']);

        $reussi = $this->executer(function () use ($documents, $stagiaire, $valides) {
            $chemin = $this->justificatif !== null
                ? $documents->enregistrer($this->justificatif, "regimes/{$stagiaire->id}", config('internat.documents.extensions'), (int) config('internat.documents.taille_max_ko'))['chemin']
                : null;

            RegimeAlimentaire::create([
                'stagiaire_id' => $stagiaire->id,
                'type' => $valides['type'],
                'precision_regime' => $valides['precision'] ?: null,
                'justificatif_fichier' => $chemin,
            ]);
        }, 'Régime alimentaire déclaré.');

        if ($reussi) {
            $this->reset('type', 'precision', 'justificatif');
            $this->fermerDialogue();
        }
    }

    public function retirerRegime(Documents $documents, int $regime): void
    {
        $ligne = RegimeAlimentaire::where('stagiaire_id', $this->stagiaire()->id)->findOrFail($regime);

        $documents->supprimer($ligne->justificatif_fichier);
        $ligne->delete();
        $this->message('Régime retiré.');
    }

    /* -------------------------------------------------------------- Affichage */

    /** @return Collection<string, Collection<int, object>> repas des quatorze prochains jours, par jour */
    private function lignes(): Collection
    {
        $restauration = app(Restauration::class);
        $stagiaire = $this->stagiaire();
        $affectation = $stagiaire->affectationActive();

        if ($affectation === null) {
            return collect();
        }

        $reservations = ReservationRepas::where('stagiaire_id', $stagiaire->id)->get()->keyBy('menu_id');

        return Menu::where('internat_id', $affectation->internatId())
            ->where('statut', StatutMenu::Valide->value)
            ->whereBetween('date_repas', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->with('service')
            ->orderBy('date_repas')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")
            ->get()
            ->map(function (Menu $menu) use ($reservations, $restauration, $stagiaire) {
                $reservation = $reservations->get($menu->id);
                $reserve = $reservation?->statut === StatutReservation::Reserve;
                $limite = $restauration->limiteDeReservation($menu);
                $modifiable = $menu->service?->effectif_confirme === null && now()->lessThanOrEqualTo($limite);
                $blocage = $reserve ? null : $restauration->motifIneligibilite($stagiaire, $menu);

                return (object) [
                    'menu' => $menu,
                    'reservation' => $reservation,
                    'reserve' => $reserve,
                    'limite' => $limite,
                    'modifiable' => $modifiable,
                    'blocage' => $blocage,
                    'reservable' => $modifiable && ! $reserve && $blocage === null,
                ];
            })
            ->groupBy(fn (object $ligne) => $ligne->menu->date_repas->toDateString());
    }

    public function render()
    {
        $stagiaire = $this->stagiaire();
        $jours = $this->lignes();
        $toutes = $jours->flatten(1);
        $vises = $this->vises();
        $choisis = $toutes->filter(fn (object $l) => in_array($l->menu->id, $vises, true));

        return view('livewire.stagiaire.repas', [
            'resident' => $stagiaire->affectationActive() !== null,
            'jours' => $jours,
            'reservables' => $toutes->where('reservable', true)->count(),
            'mesReservations' => $toutes->where('reserve', true)->values(),
            'choisis' => $choisis->values(),
            'regimes' => RegimeAlimentaire::where('stagiaire_id', $stagiaire->id)->get(),
            'enModification' => $this->dialogue === 'allergie' && $this->ligne !== null
                ? ReservationRepas::with('menu')->where('stagiaire_id', $stagiaire->id)->find($this->ligne)
                : null,
        ]);
    }
}
