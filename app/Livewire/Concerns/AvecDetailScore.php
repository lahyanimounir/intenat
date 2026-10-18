<?php

namespace App\Livewire\Concerns;

use App\Domain\Admissions\CalculScore;
use App\Enums\StatutCampagne;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use Livewire\Attributes\Locked;

/**
 * Fenêtre « Détail du score » d'un dossier : chaque critère, la déclaration, la
 * pièce et les points. Réservée aux comptes qui voient le classement, dans leur
 * périmètre.
 */
trait AvecDetailScore
{
    #[Locked]
    public ?int $scoreDe = null;

    public function voirScore(int $demande): void
    {
        $modele = Demande::findOrFail($demande);
        $this->autoriser($modele, 'admissions.consulter');

        $this->scoreDe = $demande;
        $this->dialogue = 'score';
        $this->primerNotes($modele);
    }

    /** Point d'extension : amorcer l'édition des notes à l'ouverture du détail (voir Classement). */
    protected function primerNotes(Demande $demande): void {}

    /**
     * La campagne du dossier accepte-t-elle une rectification manuelle des notes ?
     * Ouvert au compte qui a la permission, uniquement après clôture et avant délibération.
     */
    protected function notesAjustables(Demande $demande): bool
    {
        return $this->utilisateur()->can('admissions.noter')
            && $demande->campagne->statut === StatutCampagne::Cloturee
            && ! $demande->campagne->estDeliberee();
    }

    /** @return array<string, mixed>|null */
    protected function detailScore(): ?array
    {
        if ($this->dialogue !== 'score' || $this->scoreDe === null) {
            return null;
        }

        $demande = Demande::with('campagne.criteres.typePiece', 'pieces', 'stagiaire', 'notesManuelles.auteur:id,nom,prenom')->findOrFail($this->scoreDe);
        $this->autoriser($demande, 'admissions.consulter');
        $evaluation = app(CalculScore::class)->evaluer($demande);

        return [
            'demande' => $demande,
            'recalcule' => $evaluation['total'],
            'lignes' => $evaluation['lignes'],
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'ajustable' => $this->notesAjustables($demande),
            'notesManuelles' => $demande->notesManuelles->keyBy('critere_id'),
            'decisions' => DecisionAdmission::where('demande_id', $demande->id)->with('validateur:id,nom,prenom')->orderByDesc('date_validation')->get(),
        ];
    }
}
