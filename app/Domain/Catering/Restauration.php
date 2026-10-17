<?php

namespace App\Domain\Catering;

use App\Domain\Audit\Journal;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Models\Menu;
use App\Models\ReservationRepas;
use App\Models\ServiceRepas;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Restauration : menus, réservations, effectifs J+1 et suivi du service.
 *
 * Trois effectifs restent distincts pour chaque repas :
 *   réservé ..... ce que les stagiaires ont demandé
 *   confirmé .... ce que le responsable arrête la veille (J+1)
 *   servi ....... ce qui a réellement été servi
 * La gestion des stocks, des coûts et des bons de commande est hors périmètre.
 */
class Restauration
{
    public function __construct(
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    public function validerMenu(Menu $menu, Utilisateur $responsable): Menu
    {
        return DB::transaction(function () use ($menu, $responsable) {
            $menu = Menu::lockForUpdate()->findOrFail($menu->id);

            if ($menu->statut === StatutMenu::Valide) {
                throw RegleMetierException::conflit('Ce menu est déjà validé.', 'deja_valide');
            }

            $menu->forceFill([
                'statut' => StatutMenu::Valide,
                'date_validation' => now(),
                'valide_par' => $responsable->id,
            ])->save();

            ServiceRepas::firstOrCreate(['menu_id' => $menu->id], ['date_service' => $menu->date_repas]);

            $this->journal->consigner('menu.validation', $menu, details: [
                'date' => $menu->date_repas->toDateString(), 'repas' => $menu->type_repas->value,
            ]);

            return $menu;
        });
    }

    /** Dernier instant pour réserver ou annuler : la veille du repas, à l'heure limite. */
    public function limiteDeReservation(Menu $menu): CarbonInterface
    {
        [$heure, $minute] = array_map('intval', explode(':', $this->parametres->texte('restauration.heure_limite_reservation')) + [0, 0]);

        return $menu->date_repas->copy()->subDay()->setTime($heure, $minute);
    }

    /**
     * Raison pour laquelle un stagiaire ne peut pas réserver ce repas, ou null.
     */
    public function motifIneligibilite(Stagiaire $stagiaire, Menu $menu): ?string
    {
        $affectation = $stagiaire->affectationActive();

        if ($affectation === null) {
            return 'La réservation des repas est réservée aux résidents hébergés.';
        }

        if ($affectation->internatId() !== $menu->internat_id) {
            return 'Ce repas est servi dans un autre internat.';
        }

        if ($menu->statut !== StatutMenu::Valide) {
            return 'Ce menu n\'est pas encore diffusé.';
        }

        if ($menu->service?->effectif_confirme !== null) {
            return 'L\'effectif de ce repas est déjà confirmé.';
        }

        if (now()->greaterThan($this->limiteDeReservation($menu))) {
            return 'Le délai de réservation de ce repas est dépassé.';
        }

        return null;
    }

    public function reserver(Stagiaire $stagiaire, Menu $menu): ReservationRepas
    {
        return DB::transaction(function () use ($stagiaire, $menu) {
            $menu = Menu::with('service')->lockForUpdate()->findOrFail($menu->id);
            $motif = $this->motifIneligibilite($stagiaire, $menu);

            if ($motif !== null) {
                throw new RegleMetierException($motif, 'reservation_impossible');
            }

            $reservation = ReservationRepas::firstOrNew(['menu_id' => $menu->id, 'stagiaire_id' => $stagiaire->id]);

            if ($reservation->exists && $reservation->statut === StatutReservation::Reserve) {
                return $reservation;
            }

            $reservation->fill([
                'statut' => StatutReservation::Reserve,
                'date_reservation' => now(),
                'date_annulation' => null,
            ])->save();

            return $reservation;
        });
    }

    public function annuler(ReservationRepas $reservation): ReservationRepas
    {
        return DB::transaction(function () use ($reservation) {
            $menu = Menu::with('service')->lockForUpdate()->findOrFail($reservation->menu_id);
            $reservation = ReservationRepas::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->statut !== StatutReservation::Reserve) {
                throw new RegleMetierException('Cette réservation ne peut plus être annulée.', 'annulation_impossible');
            }

            if ($menu->service?->effectif_confirme !== null || now()->greaterThan($this->limiteDeReservation($menu))) {
                throw new RegleMetierException('Le délai d\'annulation de ce repas est dépassé.', 'annulation_impossible');
            }

            $reservation->forceFill(['statut' => StatutReservation::Annule, 'date_annulation' => now()])->save();

            return $reservation;
        });
    }

    /**
     * Confirmation de l'effectif prévisionnel (J+1). Fige les réservations du repas.
     */
    public function confirmerEffectif(Menu $menu, ?int $effectif, Utilisateur $responsable): ServiceRepas
    {
        return DB::transaction(function () use ($menu, $effectif, $responsable) {
            $menu = Menu::lockForUpdate()->findOrFail($menu->id);

            if ($menu->statut !== StatutMenu::Valide) {
                throw new RegleMetierException('Validez d\'abord le menu.', 'menu_non_valide');
            }

            if ($menu->date_repas->lessThan(now()->startOfDay())) {
                throw new RegleMetierException('Ce repas est passé : l\'effectif prévisionnel ne se confirme plus.', 'repas_passe');
            }

            $service = ServiceRepas::lockForUpdate()->firstOrCreate(['menu_id' => $menu->id], ['date_service' => $menu->date_repas]);

            if ($service->effectif_confirme !== null) {
                throw RegleMetierException::conflit('L\'effectif de ce repas est déjà confirmé.', 'deja_confirme');
            }

            $reserves = $this->effectifReserve($menu->id);

            $service->forceFill([
                'effectif_reserve' => $reserves,
                'effectif_confirme' => $effectif ?? $reserves,
                'confirme_par' => $responsable->id,
                'date_confirmation' => now(),
            ])->save();

            $this->journal->consigner('restauration.effectif_confirme', $service, details: [
                'reserve' => $reserves, 'confirme' => $service->effectif_confirme,
            ]);

            return $service;
        });
    }

    /**
     * Clôture du service : pointage des réservations (servi / absent) ou saisie
     * directe du nombre de repas servis, puis contrôle de conformité.
     *
     * @param  array<int, string>  $pointage  reservation_id => 'servi' | 'absent'
     */
    public function cloturerService(Menu $menu, array $pointage, ?int $effectifServi, ?string $observation, Utilisateur $responsable): ServiceRepas
    {
        return DB::transaction(function () use ($menu, $pointage, $effectifServi, $observation, $responsable) {
            $menu = Menu::lockForUpdate()->findOrFail($menu->id);
            $service = ServiceRepas::where('menu_id', $menu->id)->lockForUpdate()->first();

            if ($service === null || $service->effectif_confirme === null) {
                throw new RegleMetierException('Confirmez l\'effectif prévisionnel avant de clore le service.', 'effectif_non_confirme');
            }

            if ($menu->date_repas->greaterThan(now()->startOfDay())) {
                throw new RegleMetierException('Le service ne se clôt pas avant le jour du repas.', 'repas_futur');
            }

            if ($service->date_cloture_service !== null) {
                throw RegleMetierException::conflit('Ce service est déjà clos.', 'service_clos');
            }

            $reservations = ReservationRepas::where('menu_id', $menu->id)
                ->where('statut', StatutReservation::Reserve->value)->lockForUpdate()->get()->keyBy('id');

            if (array_diff(array_keys($pointage), $reservations->keys()->all()) !== []) {
                throw new RegleMetierException('Le pointage vise une réservation étrangère à ce repas.', 'pointage_invalide');
            }

            foreach ($pointage as $id => $etat) {
                $reservations[$id]->forceFill([
                    'statut' => $etat === 'servi' ? StatutReservation::Servi : StatutReservation::Absent,
                ])->save();
            }

            $servis = $pointage !== []
                ? ReservationRepas::where('menu_id', $menu->id)->where('statut', StatutReservation::Servi->value)->count()
                : $effectifServi;

            if ($servis === null) {
                throw new RegleMetierException('Indiquez le nombre de repas servis ou pointez les réservations.', 'effectif_servi_requis');
            }

            // Sans pointage nominatif, les réservations restantes sont réputées servies ou absentes globalement.
            $service->forceFill([
                'effectif_servi' => $servis,
                'conforme' => $servis === $service->effectif_confirme,
                'date_cloture_service' => now(),
                'observation' => $observation,
                'confirme_par' => $service->confirme_par ?? $responsable->id,
            ])->save();

            $this->journal->consigner('restauration.service_clos', $service, details: [
                'confirme' => $service->effectif_confirme, 'servi' => $servis, 'conforme' => $service->conforme,
            ]);

            return $service;
        });
    }

    public function effectifReserve(int $menuId): int
    {
        return ReservationRepas::where('menu_id', $menuId)
            ->whereIn('statut', [StatutReservation::Reserve->value, StatutReservation::Servi->value, StatutReservation::Absent->value])
            ->count();
    }
}
