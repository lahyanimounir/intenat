<?php

namespace App\Domain\Reporting;

use App\Enums\StatutDemande;
use App\Enums\StatutLit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agrégats partagés par les tableaux de bord et les rapports. Tout indicateur
 * est calculé par une requête groupée sur les données réelles, toujours bornée
 * au périmètre fourni (`null` = aucune restriction, `[]` = aucun internat).
 */
class Indicateurs
{
    /**
     * Capacité et occupation par internat.
     *
     * @param  list<int>|null  $internatIds
     * @return Collection<int, object{internat_id: int, lits: int, occupes: int, hors_service: int, disponibles: int, taux: float}>
     */
    public function occupationParInternat(?array $internatIds): Collection
    {
        return DB::table('lit')
            ->join('chambre', 'chambre.id', '=', 'lit.chambre_id')
            ->join('batiment', 'batiment.id', '=', 'chambre.batiment_id')
            ->when($internatIds !== null, fn ($q) => $q->whereIn('batiment.internat_id', $internatIds))
            ->groupBy('batiment.internat_id')
            ->selectRaw('batiment.internat_id as internat_id, COUNT(*) as lits')
            ->selectRaw('SUM(lit.statut = ?) as occupes', [StatutLit::Occupe->value])
            ->selectRaw('SUM(lit.statut = ?) as hors_service', [StatutLit::HorsService->value])
            ->get()
            ->map(function ($ligne) {
                $ligne->lits = (int) $ligne->lits;
                $ligne->occupes = (int) $ligne->occupes;
                $ligne->hors_service = (int) $ligne->hors_service;
                $ligne->disponibles = $ligne->lits - $ligne->occupes - $ligne->hors_service;
                $utilisables = $ligne->lits - $ligne->hors_service;
                $ligne->taux = $utilisables > 0 ? round($ligne->occupes * 100 / $utilisables, 1) : 0.0;

                return $ligne;
            })
            ->keyBy('internat_id');
    }

    /**
     * Dossiers par statut et par internat, pour les campagnes non archivées.
     *
     * @param  list<int>|null  $internatIds
     * @return Collection<int, array<string, int>> internat_id => [statut => total]
     */
    public function dossiersParInternat(?array $internatIds, bool $avecArchives = false): Collection
    {
        return DB::table('demande')
            ->join('campagne', 'campagne.id', '=', 'demande.campagne_id')
            ->when($internatIds !== null, fn ($q) => $q->whereIn('campagne.internat_id', $internatIds))
            ->when(! $avecArchives, fn ($q) => $q->whereNull('campagne.archivee_le'))
            ->groupBy('campagne.internat_id', 'demande.statut')
            ->selectRaw('campagne.internat_id as internat_id, demande.statut as statut, COUNT(*) as total')
            ->get()
            ->groupBy('internat_id')
            ->map(fn (Collection $lignes) => $lignes->pluck('total', 'statut')->map(fn ($t) => (int) $t)->all());
    }

    /**
     * Synthèse admissions d'un jeu de statuts.
     *
     * @param  array<string, int>  $parStatut
     * @return array{deposes: int, a_instruire: int, complets: int, admis: int, confirmes: int, en_attente: int, refuses: int, desistes: int}
     */
    public function syntheseAdmissions(array $parStatut): array
    {
        $n = fn (StatutDemande ...$statuts) => array_sum(array_map(fn ($s) => $parStatut[$s->value] ?? 0, $statuts));

        return [
            'deposes' => array_sum($parStatut) - $n(StatutDemande::Brouillon),
            'a_instruire' => $n(StatutDemande::Recu),
            'complets' => $n(StatutDemande::Complet),
            'admis' => $n(StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::SejourTermine),
            'confirmes' => $n(StatutDemande::Confirme, StatutDemande::SejourTermine),
            'en_attente' => $n(StatutDemande::EnAttente),
            'refuses' => $n(StatutDemande::Refuse, StatutDemande::Rejete),
            'desistes' => $n(StatutDemande::Desiste, StatutDemande::DelaiDepasse),
        ];
    }

    /**
     * Nombre de résidents hébergés à la fin de chacun des derniers mois.
     *
     * @param  list<int>|null  $internatIds
     * @return list<array{mois: string, residents: int}>
     */
    public function tendanceOccupation(?array $internatIds, int $mois = 6): array
    {
        $serie = [];
        $debut = CarbonImmutable::now()->startOfMonth()->subMonths($mois - 1);

        for ($i = 0; $i < $mois; $i++) {
            $fin = $debut->addMonths($i)->endOfMonth();
            $reference = $fin->isFuture() ? CarbonImmutable::now() : $fin;

            $residents = DB::table('affectation')
                ->join('demande', 'demande.id', '=', 'affectation.demande_id')
                ->join('campagne', 'campagne.id', '=', 'demande.campagne_id')
                ->when($internatIds !== null, fn ($q) => $q->whereIn('campagne.internat_id', $internatIds))
                ->whereDate('affectation.date_debut', '<=', $reference->toDateString())
                ->where(fn ($q) => $q->whereNull('affectation.date_fin')
                    ->orWhereDate('affectation.date_fin', '>', $reference->toDateString()))
                ->count();

            $serie[] = ['mois' => $fin->format('Y-m'), 'residents' => $residents];
        }

        return $serie;
    }

    /**
     * Raisons de désistement (statistiques d'abandon).
     *
     * @param  list<int>|null  $internatIds
     * @return array<string, int>
     */
    public function raisonsDesistement(?array $internatIds): array
    {
        return DB::table('desistement')
            ->join('demande', 'demande.id', '=', 'desistement.demande_id')
            ->join('campagne', 'campagne.id', '=', 'demande.campagne_id')
            ->when($internatIds !== null, fn ($q) => $q->whereIn('campagne.internat_id', $internatIds))
            ->groupBy('desistement.raison')
            ->selectRaw('desistement.raison as raison, COUNT(*) as total')
            ->pluck('total', 'raison')
            ->map(fn ($t) => (int) $t)
            ->all();
    }
}
