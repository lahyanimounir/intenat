<?php

namespace App\Domain\Catering;

use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRepas;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Liste nominative des repas réservés d'un jour (par défaut J+1), établie sur
 * les réservations réelles : stagiaire, établissement, internat, repas, régimes
 * déclarés et allergie signalée pour le repas. Une ligne porteuse d'un régime ou
 * d'une allergie est « à surveiller ».
 */
class ListeDesRepas
{
    public const AUCUN_REGIME = 'Aucun régime déclaré';

    /**
     * @return Collection<int, object{reservation: int, nom: string, prenom: string, cef: string, etablissement: string,
     *     internat: string, date: CarbonInterface, repas: TypeRepas, regimes: string, sansRegime: bool, allergie: ?string, attention: bool}>
     */
    public function lignes(Utilisateur $compte, CarbonInterface $jour, ?int $internatId = null, ?TypeRepas $repas = null): Collection
    {
        $ordre = array_flip(array_map(fn (TypeRepas $t) => $t->value, TypeRepas::cases()));

        return ReservationRepas::query()
            ->whereIn('statut', [StatutReservation::Reserve->value, StatutReservation::Servi->value, StatutReservation::Absent->value])
            ->whereHas('menu', fn (Builder $q) => $q->visiblesPar($compte)
                ->whereDate('date_repas', $jour)->where('statut', StatutMenu::Valide->value)
                ->when($internatId !== null, fn (Builder $s) => $s->where('internat_id', $internatId))
                ->when($repas !== null, fn (Builder $s) => $s->where('type_repas', $repas->value)))
            ->with('menu:id,internat_id,date_repas,type_repas', 'menu.internat:id,nom', 'stagiaire:id,nom,prenom,cef,etablissement_id', 'stagiaire.etablissement:id,nom_efp', 'stagiaire.regimes')
            ->get()
            ->map(function (ReservationRepas $r) {
                $regimes = $r->stagiaire->regimes
                    ->map(fn (RegimeAlimentaire $g) => $g->type->libelle().($g->precision_regime ? ' ('.$g->precision_regime.')' : ''))
                    ->implode(' · ');

                return (object) [
                    'reservation' => $r->id,
                    'nom' => $r->stagiaire->nom,
                    'prenom' => $r->stagiaire->prenom,
                    'cef' => (string) $r->stagiaire->cef,
                    'etablissement' => $r->stagiaire->etablissement?->nom_efp ?? '—',
                    'internat' => $r->menu->internat->nom,
                    'date' => $r->menu->date_repas,
                    'repas' => $r->menu->type_repas,
                    'regimes' => $regimes !== '' ? $regimes : self::AUCUN_REGIME,
                    'sansRegime' => $regimes === '',
                    'allergie' => $r->allergie,
                    'attention' => $regimes !== '' || filled($r->allergie),
                ];
            })
            ->sortBy(fn (object $l) => sprintf('%d|%s|%s %s', $ordre[$l->repas->value], $l->internat, $l->nom, $l->prenom))
            ->values();
    }

    /**
     * Total par type de repas, dans l'ordre de la journée, avec le nombre de lignes à surveiller.
     *
     * @param  Collection<int, object>  $lignes
     * @return array<string, array{repas: TypeRepas, total: int, attention: int}>
     */
    public function totaux(Collection $lignes): array
    {
        $totaux = [];

        foreach (TypeRepas::cases() as $type) {
            $duType = $lignes->filter(fn (object $l) => $l->repas === $type);
            $totaux[$type->value] = ['repas' => $type, 'total' => $duType->count(), 'attention' => $duType->where('attention', true)->count()];
        }

        return $totaux;
    }
}
