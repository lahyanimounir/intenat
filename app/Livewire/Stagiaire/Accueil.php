<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Applications\DossierStagiaire;
use App\Domain\Payments\SuiviPaiements;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutPiece;
use App\Enums\StatutReservation;
use App\Livewire\PageStagiaire;
use App\Models\NotificationInterne;
use App\Models\ReservationRepas;
use Livewire\Attributes\Title;

/** Accueil du stagiaire : ce qui attend une action de sa part, sa demande, son séjour. */
#[Title('Accueil')]
class Accueil extends PageStagiaire
{
    public function render()
    {
        $dossiers = app(DossierStagiaire::class);
        $stagiaire = $this->stagiaire();
        $demande = $stagiaire->demandeCourante();

        $manquantes = $demande !== null && $demande->estModifiableParLeStagiaire()
            ? $dossiers->piecesAttendues($demande)
                ->filter(fn (array $p) => $p['requise'] && ($p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer))
                ->map(fn (array $p) => $p['type']->libelle)->values()
            : collect();

        return view('livewire.stagiaire.accueil', [
            'stagiaire' => $stagiaire,
            'demande' => $demande,
            'campagneOuverte' => $demande === null ? $dossiers->campagneOuverte($stagiaire)?->load('internat') : null,
            'piecesManquantes' => $manquantes,
            'affectation' => $stagiaire->affectationActive(),
            'heberge' => $stagiaire->aUnLitAffecte(),
            // Mensualités à régler : sans justificatif, ou dont le justificatif a été rejeté.
            'paiementsARegler' => ! $stagiaire->aUnLitAffecte() ? 0 : $demande?->paiements()->whereIn('statut', array_map(fn ($s) => $s->value, SuiviPaiements::A_REGLER))->count() ?? 0,
            'repasReserves' => ReservationRepas::where('stagiaire_id', $stagiaire->id)->where('statut', StatutReservation::Reserve->value)
                ->whereHas('menu', fn ($q) => $q->where('statut', StatutMenu::Valide->value)->whereDate('date_repas', '>=', now()->toDateString()))->count(),
            'notifications' => NotificationInterne::where('stagiaire_id', $stagiaire->id)->where('lue', false)->latest('date_envoi')->limit(5)->get(),
        ]);
    }
}
