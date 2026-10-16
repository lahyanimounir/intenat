<?php

namespace App\Domain\Catering;

use App\Domain\Identity\Perimetre;
use App\Enums\StatutMenu;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use App\Models\Demande;
use App\Models\RegimeAlimentaire;
use App\Models\Utilisateur;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chiffres mensuels de la restauration dans le périmètre d'un compte : repas
 * réservés, confirmés, servis, et conformité des services. Ni stocks, ni
 * coûts, ni bons de commande : ils n'entrent pas dans le périmètre de
 * l'application.
 */
class StatistiquesRestauration
{
    private const AGREGATS = 'COUNT(menu.id) as repas, COALESCE(SUM(service_repas.effectif_reserve), 0) as reserves, '
        .'COALESCE(SUM(service_repas.effectif_confirme), 0) as confirmes, COALESCE(SUM(service_repas.effectif_servi), 0) as servis, '
        .'COALESCE(SUM(service_repas.conforme = 1), 0) as conformes, COUNT(service_repas.effectif_servi) as services_clos';

    public function __construct(private readonly Perimetre $perimetre) {}

    /**
     * @param  string  $mois  AAAA-MM
     * @return array{
     *     total: array<string, int>,
     *     par_type: Collection<int, array<string, mixed>>,
     *     par_jour: Collection<int, array<string, mixed>>,
     *     regimes: Collection<int, array{type: TypeRegime, total: int}>
     * }
     */
    public function duMois(Utilisateur $utilisateur, string $mois): array
    {
        $ids = $this->perimetre->internatIds($utilisateur);
        $debut = Carbon::parse($mois.'-01')->startOfDay();

        $base = fn () => DB::table('menu')
            ->leftJoin('service_repas', 'service_repas.menu_id', '=', 'menu.id')
            ->when($ids !== null, fn ($q) => $q->whereIn('menu.internat_id', $ids))
            ->whereBetween('menu.date_repas', [$debut->toDateString(), $debut->copy()->endOfMonth()->toDateString()])
            ->where('menu.statut', StatutMenu::Valide->value);

        return [
            'total' => self::entiers($base()->selectRaw(self::AGREGATS)->first()),
            'par_type' => $base()->groupBy('menu.type_repas')->selectRaw('menu.type_repas as type_repas, '.self::AGREGATS)->get()
                ->map(fn (object $l) => ['type_repas' => TypeRepas::from($l->type_repas)] + self::entiers($l))
                ->sortBy(fn (array $l) => array_search($l['type_repas'], TypeRepas::cases(), true))->values(),
            'par_jour' => $base()->groupBy('menu.date_repas')->orderBy('menu.date_repas')->selectRaw('menu.date_repas as jour, '.self::AGREGATS)->get()
                ->map(fn (object $l) => ['jour' => Carbon::parse($l->jour)] + self::entiers($l)),
            'regimes' => RegimeAlimentaire::query()->whereIn('stagiaire_id', $this->residents($utilisateur))
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')->get()
                ->map(fn (RegimeAlimentaire $l) => ['type' => $l->type, 'total' => (int) $l->total]),
        ];
    }

    /**
     * Régimes particuliers des résidents actuellement hébergés dans le périmètre.
     *
     * @return Collection<int, RegimeAlimentaire>
     */
    public function regimes(Utilisateur $utilisateur): Collection
    {
        return RegimeAlimentaire::whereIn('stagiaire_id', $this->residents($utilisateur))
            ->with('stagiaire:id,nom,prenom,cef')->get()
            ->sortBy(fn (RegimeAlimentaire $r) => $r->stagiaire->nom.' '.$r->stagiaire->prenom)->values();
    }

    /** Un régime n'est lisible que si son déclarant est hébergé dans le périmètre du compte. */
    public function couvre(Utilisateur $utilisateur, RegimeAlimentaire $regime): bool
    {
        return $this->residents($utilisateur)->where('stagiaire_id', $regime->stagiaire_id)->exists();
    }

    /** Stagiaires actuellement hébergés dans le périmètre (sous-requête). */
    private function residents(Utilisateur $utilisateur): Builder
    {
        return Demande::visiblesPar($utilisateur)->whereHas('affectationActive')->select('stagiaire_id');
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
}
