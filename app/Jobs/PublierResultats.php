<?php

namespace App\Jobs;

use App\Domain\Notifications\Notifieur;
use App\Enums\StatutDemande;
use App\Enums\TypeNotification;
use App\Models\Demande;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publication des résultats : notifie chaque candidat délibéré.
 * Rejouable sans doublon grâce à la clé d'unicité des notifications.
 */
class PublierResultats implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $campagneId) {}

    public function handle(Notifieur $notifieur): void
    {
        Demande::query()
            ->where('campagne_id', $this->campagneId)
            ->whereIn('statut', [StatutDemande::Admis->value, StatutDemande::EnAttente->value, StatutDemande::Refuse->value])
            ->with('stagiaire')
            ->chunkById(200, function ($dossiers) use ($notifieur) {
                foreach ($dossiers as $dossier) {
                    [$titre, $message] = match ($dossier->statut) {
                        StatutDemande::Admis => [
                            'Résultat : admis',
                            'Vous êtes admis à l\'internat. Confirmez votre place pour la conserver.',
                        ],
                        StatutDemande::EnAttente => [
                            'Résultat : liste d\'attente',
                            'Votre dossier est placé en liste d\'attente. Vous serez informé si une place se libère.',
                        ],
                        default => [
                            'Résultat : non retenu',
                            'Votre demande de place à l\'internat n\'a pas été retenue.',
                        ],
                    };

                    $notifieur->versStagiaire(
                        $dossier->stagiaire,
                        TypeNotification::Resultat,
                        $titre,
                        $message,
                        '/stagiaire/dossier',
                        "resultat:{$dossier->id}",
                    );
                }
            });
    }
}
