<?php

namespace App\Livewire\Personnel;

use App\Domain\Catering\CarteDesMenus;
use App\Domain\Catering\Restauration;
use App\Domain\Catering\ValidationDesMenus;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRepas;
use App\Livewire\PagePersonnel;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Carte de la semaine (petit-déjeuner, déjeuner, dîner). Le responsable
 * restauration compose la semaine puis la transmet au chef de pôle, qui la
 * vérifie et la valide — ou la renvoie pour correction. Seul un menu validé
 * est diffusé aux stagiaires, qui réservent sur cette base.
 */
#[Title('Menus')]
class Menus extends PagePersonnel
{
    private const CONSULTER = 'restauration.consulter';

    private const GERER = 'restauration.gerer';

    private const VALIDER = 'restauration.valider_menus';

    /** Décalage, en semaines, par rapport à la semaine en cours. */
    #[Url(except: 0)]
    public int $semaine = 0;

    #[Url(except: '')]
    public string $internat = '';

    /** Menu en cours de modification ; nul pour une proposition. */
    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, mixed> */
    public array $saisie = ['jour' => '', 'repas' => '', 'description' => '', 'internats' => []];

    public function mount(): void
    {
        $this->exiger(self::CONSULTER);
        $this->semaine = max(-104, min(104, $this->semaine));
    }

    public function decaler(int $pas): void
    {
        $this->semaine = max(-104, min(104, $this->semaine + ($pas < 0 ? -1 : 1)));
    }

    public function cetteSemaine(): void
    {
        $this->semaine = 0;
    }

    private function lundi(): Carbon
    {
        return now()->startOfWeek(Carbon::MONDAY)->addWeeks($this->semaine)->startOfDay();
    }

    private function menu(int $id): Menu
    {
        $menu = Menu::findOrFail($id);
        $this->autoriser($menu, self::GERER);

        return $menu;
    }

    public function proposer(?string $jour = null, ?string $repas = null): void
    {
        $this->exiger(self::GERER);
        $aujourdhui = now()->startOfDay();
        $propose = rescue(fn () => $jour ? Carbon::parse($jour)->startOfDay() : null, null, false) ?? max($this->lundi(), $aujourdhui);

        $this->ligne = null;
        $this->saisie = [
            'jour' => ($propose->lessThan($aujourdhui) ? $aujourdhui : $propose)->toDateString(),
            'repas' => TypeRepas::tryFrom((string) $repas)?->value ?? TypeRepas::Dejeuner->value,
            'description' => '',
            // Par défaut, le repas est proposé dans tous les internats servis par ce compte.
            'internats' => $this->internatsDuPerimetre()->keys()->map(fn ($id) => (string) $id)->all(),
        ];
        $this->ouvrirDialogue('menu');
    }

    public function modifier(int $id): void
    {
        $menu = $this->menu($id);
        $this->ligne = $menu->id;
        $this->saisie = ['jour' => $menu->date_repas->toDateString(), 'repas' => $menu->type_repas->value, 'description' => $menu->description, 'internats' => []];
        $this->ouvrirDialogue('menu');
    }

    public function enregistrer(CarteDesMenus $carte): void
    {
        $this->exiger(self::GERER);

        if ($this->ligne !== null) {
            $menu = $this->menu($this->ligne);
            $description = $this->validate(['saisie.description' => ['required', 'string', 'min:3', 'max:1000']], attributes: ['saisie.description' => 'composition du repas'])['saisie']['description'];

            $message = $menu->transmis_le !== null ? 'Menu modifié : retransmettez la semaine au chef de pôle.' : 'Menu modifié.';

            if ($this->executer(fn () => $carte->modifier($menu, trim($description)), $message)) {
                $this->fermerDialogue();
            }

            return;
        }

        $valides = $this->validate([
            'saisie.jour' => ['required', 'date', 'after_or_equal:today'],
            'saisie.repas' => ['required', Rule::enum(TypeRepas::class)],
            'saisie.description' => ['required', 'string', 'min:3', 'max:1000'],
            'saisie.internats' => ['required', 'array', 'min:1'],
            'saisie.internats.*' => ['integer', 'distinct'],
        ], [
            'saisie.jour.after_or_equal' => 'Un menu ne se propose pas pour un jour passé.',
            'saisie.internats.required' => 'Choisissez au moins un internat.',
        ], [
            'saisie.jour' => 'jour', 'saisie.repas' => 'repas', 'saisie.description' => 'composition du repas', 'saisie.internats' => 'internats servis',
        ])['saisie'];

        if ($this->executer(fn () => $carte->proposer(
            array_map('intval', $valides['internats']), Carbon::parse($valides['jour']), TypeRepas::from($valides['repas']), trim($valides['description']), $this->utilisateur(),
        ), 'Menu proposé : transmettez la semaine au chef de pôle pour validation.')) {
            $this->fermerDialogue();
        }
    }

    private function internatFiltre(): ?int
    {
        return $this->internat !== '' ? (int) $this->internat : null;
    }

    /* ------------------------------------------------ Transmission et validation */

    public function transmettreSemaine(ValidationDesMenus $validation): void
    {
        $this->exiger(self::GERER);

        $this->executer(function () use ($validation) {
            $nombre = $validation->transmettreSemaine($this->lundi(), $this->internatFiltre(), $this->utilisateur());
            $this->message("{$nombre} menu(s) transmis au chef de pôle pour validation.");
        });
    }

    public function valider(Restauration $restauration, int $id): void
    {
        $menu = Menu::findOrFail($id);
        $this->autoriser($menu, self::VALIDER);

        $this->executer(fn () => $restauration->validerMenu($menu, $this->utilisateur()), 'Menu validé : il est ouvert à la réservation.');
    }

    public function validerSemaine(ValidationDesMenus $validation): void
    {
        $this->exiger(self::VALIDER);

        $this->executer(function () use ($validation) {
            $nombre = $validation->validerSemaine($this->lundi(), $this->internatFiltre(), $this->utilisateur());
            $this->message("Semaine validée : {$nombre} menu(s) ouverts à la réservation.");
        });
    }

    public function renvoyerSemaine(ValidationDesMenus $validation, string $motif): void
    {
        $this->exiger(self::VALIDER);
        $motif = trim($motif);

        if (mb_strlen($motif) < 5) {
            $this->message('Indiquez au responsable restauration ce qui est à corriger (5 caractères au moins).', 'danger');

            return;
        }

        $this->executer(function () use ($validation, $motif) {
            $nombre = $validation->renvoyerSemaine($this->lundi(), $this->internatFiltre(), mb_substr($motif, 0, 500), $this->utilisateur());
            $this->message("{$nombre} menu(s) renvoyés au responsable restauration pour correction.");
        });
    }

    public function voirHistorique(): void
    {
        $this->exiger(self::CONSULTER);
        $this->ouvrirDialogue('historique');
    }

    public function supprimer(CarteDesMenus $carte, int $id): void
    {
        $menu = $this->menu($id);

        $this->executer(fn () => $carte->supprimer($menu), 'Menu supprimé.');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::CONSULTER);

        if ($this->internat !== '') {
            $this->perimetre()->exigerInternat($this->utilisateur(), (int) $this->internat);
        }

        $lundi = $this->lundi();
        $jours = collect(range(0, 6))->map(fn (int $i) => $lundi->copy()->addDays($i));

        $menus = Menu::visiblesPar($this->utilisateur())
            ->whereBetween('date_repas', [$lundi->toDateString(), $jours->last()->toDateString()])
            ->when($this->internat !== '', fn (Builder $q) => $q->where('internat_id', (int) $this->internat))
            ->with('internat:id,nom', 'validateur:id,nom,prenom')
            ->withCount(['reservations as reserves' => fn (Builder $q) => $q->where('statut', '!=', StatutReservation::Annule->value)])
            ->orderBy('date_repas')->orderBy('internat_id')->get();

        $aVenir = $menus->filter(fn (Menu $m) => $m->statut === StatutMenu::Propose && $m->date_repas->greaterThanOrEqualTo(now()->startOfDay()));

        return view('livewire.personnel.menus', [
            'jours' => $jours,
            'parRepas' => $menus->groupBy(fn (Menu $m) => $m->date_repas->toDateString().'|'.$m->type_repas->value),
            'aTransmettre' => $aVenir->whereNull('transmis_le')->count(),
            'enAttente' => $menus->filter(fn (Menu $m) => $m->enAttenteDeValidation())->count(),
            'valides' => $menus->where('statut', StatutMenu::Valide)->count(),
            'internats' => $this->internatsDuPerimetre(),
            'gerer' => $this->peut(self::GERER),
            'valider' => $this->peut(self::VALIDER),
            'historique' => $this->dialogue === 'historique'
                ? app(ValidationDesMenus::class)->historique($this->perimetre()->internatIds($this->utilisateur()), $this->internatFiltre())
                : collect(),
            'edite' => $this->dialogue === 'menu' && $this->ligne !== null ? Menu::with('internat:id,nom')->find($this->ligne) : null,
        ]);
    }
}
