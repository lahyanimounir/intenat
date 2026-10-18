<?php

namespace App\Domain\Admissions;

use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutCampagne;
use App\Models\Critere;
use App\Models\Demande;
use App\Models\NoteManuelle;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Rectification manuelle des notes de classement par le chef de pôle.
 *
 * Fenêtre d'ouverture : la campagne est clôturée mais pas encore délibérée.
 * Hors de cette fenêtre, la note est figée (saisies encore ouvertes tant que la
 * campagne est ouverte, résultats verrouillés dès la délibération).
 *
 * Chaque critère se corrige indépendamment. Toute rectification est conservée en
 * base ({@see NoteManuelle}), recalcule aussitôt le score total du dossier puis
 * réordonne tout le classement de la campagne.
 */
class NotationManuelle
{
    public function __construct(
        private readonly CalculScore $calcul,
        private readonly Classement $classement,
    ) {}

    /** Fixe la note d'un critère pour un dossier, puis recalcule score et rang. */
    public function fixer(Demande $demande, Critere $critere, int $points, ?string $motif, Utilisateur $agent): Demande
    {
        $campagne = $this->campagneModifiable($demande, $critere);

        $maximum = (int) $critere->points;
        if ($points < 0 || $points > $maximum) {
            throw new RegleMetierException(
                "La note doit être comprise entre 0 et {$maximum} point(s) pour ce critère.",
                'note_hors_bornes',
            );
        }

        DB::transaction(function () use ($demande, $critere, $points, $motif, $agent, $campagne) {
            NoteManuelle::updateOrCreate(
                ['demande_id' => $demande->id, 'critere_id' => $critere->id],
                ['points' => $points, 'motif' => $motif, 'ajuste_par' => $agent->id, 'ajuste_le' => now()],
            );

            $this->recomposer($demande, $campagne);
        });

        return $demande->fresh();
    }

    /** Rétablit la note automatique d'un critère (supprime la rectification), puis recalcule. */
    public function retablir(Demande $demande, Critere $critere): Demande
    {
        $campagne = $this->campagneModifiable($demande, $critere);

        DB::transaction(function () use ($demande, $critere, $campagne) {
            NoteManuelle::where('demande_id', $demande->id)->where('critere_id', $critere->id)->delete();

            $this->recomposer($demande, $campagne);
        });

        return $demande->fresh();
    }

    /** Recalcule le score du dossier (l'override est lu par le moteur) et réordonne le classement. */
    private function recomposer(Demande $demande, $campagne): void
    {
        $this->calcul->calculer($demande->fresh());
        $this->classement->recalculer($campagne);
    }

    private function campagneModifiable(Demande $demande, Critere $critere)
    {
        $demande->loadMissing('campagne');
        $campagne = $demande->campagne;

        if ($critere->campagne_id !== $campagne->id) {
            throw new RegleMetierException('Ce critère n’appartient pas à la campagne du dossier.', 'critere_hors_campagne');
        }

        if ($campagne->statut !== StatutCampagne::Cloturee) {
            throw new RegleMetierException(
                'Les notes ne sont modifiables qu’après la clôture de la campagne.',
                'campagne_non_cloturee',
            );
        }

        if ($campagne->estDeliberee()) {
            throw new RegleMetierException(
                'Les admissions sont validées : les notes sont figées.',
                'campagne_deliberee',
            );
        }

        return $campagne;
    }
}
