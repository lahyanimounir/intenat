<?php

namespace App\Domain\Admissions;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutDemande;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\Demande;
use App\Models\NoteManuelle;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Rectification manuelle des notes de classement par le chef de pôle.
 *
 * Ouverte pour tout dossier déjà noté (déclaré complet par le gestionnaire), quel que soit
 * son statut ensuite : admis, en liste d'attente, place confirmée, lit affecté… Seule une
 * campagne archivée garde ses notes figées.
 *
 * Chaque critère se corrige indépendamment. Toute rectification est conservée en base
 * ({@see NoteManuelle}) et tracée au journal ; elle recalcule aussitôt le score total du
 * dossier, puis son rang :
 *  - avant la délibération, tout le classement est recalculé (listes comprises) ;
 *  - après la délibération, seul le rang est actualisé : admission, décisions de
 *    délibération et affectation à un lit restent inchangées.
 */
class NotationManuelle
{
    /** Un dossier n'a de score qu'une fois déclaré complet. */
    private const SANS_SCORE = [
        StatutDemande::Brouillon, StatutDemande::Recu, StatutDemande::RetourRectification, StatutDemande::Rejete,
    ];

    public function __construct(
        private readonly CalculScore $calcul,
        private readonly Classement $classement,
        private readonly Journal $journal,
    ) {}

    /** Le dossier accepte-t-il une rectification de ses notes ? */
    public static function ajustable(Demande $demande): bool
    {
        return ! in_array($demande->statut, self::SANS_SCORE, true) && ! $demande->campagne->estArchivee();
    }

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
            $avant = $demande->score;

            NoteManuelle::updateOrCreate(
                ['demande_id' => $demande->id, 'critere_id' => $critere->id],
                ['points' => $points, 'motif' => $motif, 'ajuste_par' => $agent->id, 'ajuste_le' => now()],
            );

            $this->recomposer($demande, $campagne, $critere, $avant, $points, $agent);
        });

        return $demande->fresh();
    }

    /** Rétablit la note automatique d'un critère (supprime la rectification), puis recalcule. */
    public function retablir(Demande $demande, Critere $critere, ?Utilisateur $agent = null): Demande
    {
        $campagne = $this->campagneModifiable($demande, $critere);

        DB::transaction(function () use ($demande, $critere, $campagne, $agent) {
            $avant = $demande->score;
            NoteManuelle::where('demande_id', $demande->id)->where('critere_id', $critere->id)->delete();
            $this->recomposer($demande, $campagne, $critere, $avant, null, $agent);
        });

        return $demande->fresh();
    }

    /** Recalcule le score du dossier (la note manuelle est lue par le moteur), puis son rang. */
    private function recomposer(Demande $demande, Campagne $campagne, Critere $critere, ?int $avant, ?int $note, ?Utilisateur $agent): void
    {
        $this->calcul->calculer($demande->fresh());

        $campagne->estDeliberee()
            ? $this->classement->actualiserLesRangs($campagne)
            : $this->classement->recalculer($campagne);

        $this->journal->consigner('admission.note_manuelle', $demande, details: [
            'critere' => $critere->condition_texte,
            'note' => $note ?? 'calcul automatique',
            'score_avant' => $avant,
            'score_apres' => $demande->fresh()->score,
            'apres_deliberation' => $campagne->estDeliberee(),
        ], acteur: $agent);
    }

    private function campagneModifiable(Demande $demande, Critere $critere): Campagne
    {
        $demande->loadMissing('campagne');
        $campagne = $demande->campagne;

        if ($critere->campagne_id !== $campagne->id) {
            throw new RegleMetierException('Ce critère n’appartient pas à la campagne du dossier.', 'critere_hors_campagne');
        }

        if ($campagne->estArchivee()) {
            throw new RegleMetierException('La campagne est archivée : ses notes sont figées.', 'campagne_archivee');
        }

        if (in_array($demande->statut, self::SANS_SCORE, true)) {
            throw new RegleMetierException(
                'Ce dossier n’a pas encore de score : il est noté quand le gestionnaire le déclare complet.',
                'dossier_sans_score',
            );
        }

        return $campagne;
    }
}
