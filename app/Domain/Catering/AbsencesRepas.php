<?php

namespace App\Domain\Catering;

use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRepas;
use App\Models\Menu;
use App\Models\ReservationRepas;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Absents aux repas : réservations pointées « absent » à la clôture du service.
 * Tant que le service n'est pas clos, la liste n'est pas définitive (le pointage
 * n'a pas encore eu lieu). Un même stagiaire compte une absence par repas manqué.
 */
class AbsencesRepas
{
    /**
     * Absences d'un jour, regroupées par internat puis par type de repas (dans l'ordre de la journée).
     *
     * @return Collection<int, object{internat: string, etablissement: string, repas: array<string, object{type: TypeRepas, menu: ?Menu, clos: bool, absents: Collection<int, object>}>, total: int}>
     */
    public function duJour(Utilisateur $compte, CarbonInterface $jour, ?int $internatId = null, ?int $menuId = null): Collection
    {
        $menus = Menu::visiblesPar($compte)
            ->whereDate('date_repas', $jour)->where('statut', StatutMenu::Valide->value)
            ->when($internatId !== null, fn (Builder $q) => $q->where('internat_id', $internatId))
            ->when($menuId !== null, fn (Builder $q) => $q->whereKey($menuId))
            ->with('internat:id,nom,etablissement_id', 'internat.etablissement:id,nom_efp', 'service')
            ->orderBy('internat_id')->get();

        $absents = ReservationRepas::whereIn('menu_id', $menus->modelKeys())->where('statut', StatutReservation::Absent->value)
            ->with('stagiaire:id,nom,prenom,cef')->get()
            ->sortBy(fn (ReservationRepas $r) => $r->stagiaire->nom.' '.$r->stagiaire->prenom)
            ->groupBy('menu_id');

        $types = $menuId !== null ? $menus->map->type_repas->unique()->all() : TypeRepas::cases();

        return $menus->groupBy('internat_id')->map(function (Collection $duInternat) use ($absents, $types) {
            $internat = $duInternat->first()->internat;
            $repas = [];

            foreach ($types as $type) {
                $menu = $duInternat->first(fn (Menu $m) => $m->type_repas === $type);
                $repas[$type->value] = (object) [
                    'type' => $type,
                    'menu' => $menu,
                    'clos' => $menu?->service?->date_cloture_service !== null,
                    'absents' => $menu === null ? collect() : ($absents->get($menu->id) ?? collect())->map(fn (ReservationRepas $r) => (object) [
                        'nom' => $r->stagiaire->nom, 'prenom' => $r->stagiaire->prenom, 'cef' => (string) $r->stagiaire->cef,
                    ])->values(),
                ];
            }

            return (object) [
                'internat' => $internat->nom,
                'etablissement' => $internat->etablissement?->nom_efp ?? '—',
                'repas' => $repas,
                'total' => collect($repas)->sum(fn (object $r) => $r->absents->count()),
            ];
        })->values();
    }
}
