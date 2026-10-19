<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Journal;
use App\Domain\Catering\Restauration;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRepas;
use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Demande;
use App\Models\Menu;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restauration côté responsable : menus, effectifs J+1, suivi du service et
 * statistiques. Pas de stock, de coûts ni de bons de commande (hors périmètre).
 */
class RestaurationController extends Controller
{
    public function __construct(
        private readonly Restauration $restauration,
        private readonly Journal $journal,
    ) {}

    public function menus(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du'],
            'internat_id' => ['nullable', 'integer'], 'statut' => ['nullable', Rule::enum(StatutMenu::class)],
        ]);

        $du = Carbon::parse($donnees['du'] ?? now()->startOfWeek());
        $au = Carbon::parse($donnees['au'] ?? $du->copy()->addDays(6));

        if ($du->diffInDays($au) > 62) {
            throw new RegleMetierException('La période affichée ne peut pas dépasser deux mois.', 'periode_trop_longue');
        }

        $menus = Menu::visiblesPar($this->personnel())
            ->whereBetween('date_repas', [$du->toDateString(), $au->toDateString()])
            ->when(! empty($donnees['internat_id']), fn ($q) => $q->where('internat_id', $donnees['internat_id']))
            ->when(! empty($donnees['statut']), fn ($q) => $q->where('statut', $donnees['statut']))
            ->with('internat:id,nom,genre', 'service')
            ->withCount(['reservations as reserves' => fn ($q) => $q->where('statut', '!=', StatutReservation::Annule->value)])
            ->orderBy('date_repas')->orderBy('internat_id')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")
            ->get();

        return response()->json([
            'du' => $du->toDateString(),
            'au' => $au->toDateString(),
            'menus' => $menus->map(fn (Menu $m) => self::menu($m)),
            'types_repas' => TypeRepas::options(),
        ]);
    }

    /** Crée un menu pour un ou plusieurs internats du périmètre (statut « Proposé »). */
    public function creerMenu(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'internat_ids' => ['required', 'array', 'min:1'],
            'internat_ids.*' => ['integer', 'distinct'],
            'date_repas' => ['required', 'date', 'after_or_equal:today'],
            'type_repas' => ['required', Rule::enum(TypeRepas::class)],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $utilisateur = $this->personnel();

        $crees = DB::transaction(function () use ($donnees, $utilisateur) {
            $crees = [];

            foreach ($donnees['internat_ids'] as $internatId) {
                $this->perimetre()->exigerInternat($utilisateur, (int) $internatId);

                $existe = Menu::where('internat_id', $internatId)->whereDate('date_repas', $donnees['date_repas'])
                    ->where('type_repas', $donnees['type_repas'])->exists();

                if ($existe) {
                    throw RegleMetierException::conflit('Un menu existe déjà pour ce repas, ce jour-là, dans cet internat.', 'menu_existant');
                }

                $menu = Menu::create([
                    'internat_id' => $internatId, 'date_repas' => $donnees['date_repas'], 'type_repas' => $donnees['type_repas'],
                    'description' => $donnees['description'], 'statut' => StatutMenu::Propose, 'cree_par' => $utilisateur->id,
                ]);
                $this->journal->consigner('menu.creation', $menu, details: ['date' => $donnees['date_repas'], 'repas' => $donnees['type_repas']]);
                $crees[] = $menu->id;
            }

            return $crees;
        });

        return response()->json(['ids' => $crees], 201);
    }

    public function modifierMenu(Request $request, Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.gerer');

        if ($menu->statut === StatutMenu::Valide) {
            throw new RegleMetierException('Un menu validé est diffusé aux stagiaires : il ne se modifie plus.', 'menu_valide');
        }

        $menu->fill($request->validate(['description' => ['required', 'string', 'min:3', 'max:1000']]))->save();

        return response()->json(self::menu($menu->load('internat:id,nom,genre', 'service')));
    }

    public function validerMenu(Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.valider_menus');

        return response()->json(self::menu($this->restauration->validerMenu($menu, $this->personnel())->load('internat:id,nom,genre', 'service')));
    }

    public function supprimerMenu(Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.gerer');

        if ($menu->statut === StatutMenu::Valide || $menu->reservations()->exists()) {
            throw new RegleMetierException('Seul un menu proposé et sans réservation peut être supprimé.', 'suppression_interdite');
        }

        $this->journal->consigner('menu.suppression', $menu, details: ['date' => $menu->date_repas->toDateString()]);
        $menu->delete();

        return response()->json(['message' => 'Menu supprimé.']);
    }

    /** Réservations d'un repas, pour le pointage du service. */
    public function reservations(Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.consulter');

        $reservations = ReservationRepas::where('menu_id', $menu->id)->where('statut', '!=', StatutReservation::Annule->value)
            ->with('stagiaire:id,nom,prenom,cef', 'stagiaire.regimes')->get()->sortBy(fn ($r) => $r->stagiaire->nom)->values();

        return response()->json([
            'menu' => self::menu($menu->load('internat:id,nom,genre', 'service')),
            'reservations' => $reservations->map(fn (ReservationRepas $r) => [
                'id' => $r->id, 'statut' => self::enum($r->statut),
                'stagiaire' => $r->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
                'regimes' => $r->stagiaire->regimes->map(fn ($g) => $g->type->libelle().($g->precision_regime ? ' — '.$g->precision_regime : ''))->values(),
            ]),
        ]);
    }

    public function confirmerEffectif(Request $request, Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.gerer');
        $effectif = $request->validate(['effectif_confirme' => ['nullable', 'integer', 'min:0', 'max:10000']])['effectif_confirme'] ?? null;
        $this->restauration->confirmerEffectif($menu, $effectif, $this->personnel());

        return response()->json(self::menu($menu->fresh()->load('internat:id,nom,genre', 'service')));
    }

    public function cloturerService(Request $request, Menu $menu): JsonResponse
    {
        $this->autoriser($menu, 'restauration.gerer');

        $donnees = $request->validate([
            'pointage' => ['sometimes', 'array'],
            'pointage.*' => [Rule::in(['servi', 'absent'])],
            'effectif_servi' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'observation' => ['nullable', 'string', 'max:500'],
        ]);

        $pointage = collect($donnees['pointage'] ?? [])->mapWithKeys(fn ($etat, $id) => [(int) $id => $etat])->all();
        $this->restauration->cloturerService($menu, $pointage, $donnees['effectif_servi'] ?? null, $donnees['observation'] ?? null, $this->personnel());

        return response()->json(self::menu($menu->fresh()->load('internat:id,nom,genre', 'service')));
    }

    /** Statistiques mensuelles : réservé, confirmé, servi, conformité, par type de repas. */
    public function statistiques(Request $request): JsonResponse
    {
        $mois = $request->validate(['mois' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['mois'] ?? now()->format('Y-m');
        $ids = $this->perimetre()->internatIds($this->personnel());
        $debut = Carbon::parse($mois.'-01');

        $base = fn () => DB::table('menu')
            ->leftJoin('service_repas', 'service_repas.menu_id', '=', 'menu.id')
            ->when($ids !== null, fn ($q) => $q->whereIn('menu.internat_id', $ids))
            ->whereBetween('menu.date_repas', [$debut->toDateString(), $debut->copy()->endOfMonth()->toDateString()])
            ->where('menu.statut', StatutMenu::Valide->value);

        $agregats = 'COUNT(menu.id) as repas, COALESCE(SUM(service_repas.effectif_reserve), 0) as reserves, '
            .'COALESCE(SUM(service_repas.effectif_confirme), 0) as confirmes, COALESCE(SUM(service_repas.effectif_servi), 0) as servis, '
            .'COALESCE(SUM(service_repas.conforme = 1), 0) as conformes, COUNT(service_repas.effectif_servi) as services_clos';

        return response()->json([
            'mois' => $mois,
            'par_type' => $base()->groupBy('menu.type_repas')->selectRaw('menu.type_repas as type_repas, '.$agregats)->get()
                ->map(fn ($l) => ['type_repas' => self::enum(TypeRepas::from($l->type_repas))] + self::entiers($l)),
            'par_jour' => $base()->groupBy('menu.date_repas')->orderBy('menu.date_repas')
                ->selectRaw('menu.date_repas as jour, '.$agregats)->get()
                ->map(fn ($l) => ['jour' => $l->jour] + self::entiers($l)),
            'total' => self::entiers($base()->selectRaw($agregats)->first()),
            'regimes' => RegimeAlimentaire::query()
                ->whereIn('stagiaire_id', $this->residents())
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')->get()
                ->map(fn ($l) => ['type' => self::enum($l->type), 'total' => (int) $l->total]),
        ]);
    }

    /** Régimes particuliers des résidents du périmètre, avec accès au justificatif. */
    public function regimes(): JsonResponse
    {
        return response()->json(RegimeAlimentaire::whereIn('stagiaire_id', $this->residents())
            ->with('stagiaire:id,nom,prenom,cef')->get()->map(fn (RegimeAlimentaire $r) => [
                'id' => $r->id, 'type' => self::enum($r->type), 'precision' => $r->precision_regime,
                'justificatif' => $r->justificatif_fichier !== null,
                'stagiaire' => $r->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            ]));
    }

    public function justificatifRegime(RegimeAlimentaire $regime, Documents $documents): Response
    {
        $resident = Affectation::actives()->visiblesPar($this->personnel())
            ->whereHas('demande', fn ($q) => $q->where('stagiaire_id', $regime->stagiaire_id))->exists();

        abort_unless($resident && $regime->justificatif_fichier !== null, 404);
        $this->journal->consigner('regime.consultation_justificatif', $regime);

        return $documents->servir($regime->justificatif_fichier, "justificatif-regime-{$regime->id}", null, false);
    }

    /** Stagiaires actuellement hébergés dans le périmètre de l'utilisateur (sous-requête). */
    private function residents(): Builder
    {
        return Demande::visiblesPar($this->personnel())->whereHas('affectationActive')->select('stagiaire_id');
    }

    /** @return array<string, int> */
    private static function entiers(?object $ligne): array
    {
        return [
            'repas' => (int) ($ligne->repas ?? 0), 'reserves' => (int) ($ligne->reserves ?? 0),
            'confirmes' => (int) ($ligne->confirmes ?? 0), 'servis' => (int) ($ligne->servis ?? 0),
            'conformes' => (int) ($ligne->conformes ?? 0), 'services_clos' => (int) ($ligne->services_clos ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public static function menu(Menu $m): array
    {
        return [
            'id' => $m->id,
            'internat' => $m->internat->only(['id', 'nom']),
            'date_repas' => $m->date_repas->toDateString(),
            'type_repas' => self::enum($m->type_repas),
            'description' => $m->description,
            'statut' => self::enum($m->statut),
            'date_validation' => $m->date_validation,
            'reserves' => $m->reserves ?? $m->reservations()->where('statut', '!=', StatutReservation::Annule->value)->count(),
            'service' => $m->service ? [
                'effectif_reserve' => $m->service->effectif_reserve,
                'effectif_confirme' => $m->service->effectif_confirme,
                'effectif_servi' => $m->service->effectif_servi,
                'conforme' => $m->service->conforme,
                'date_confirmation' => $m->service->date_confirmation,
                'date_cloture_service' => $m->service->date_cloture_service,
                'observation' => $m->service->observation,
            ] : null,
        ];
    }
}
