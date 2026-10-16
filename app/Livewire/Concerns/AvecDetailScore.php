<?php

namespace App\Livewire\Concerns;

use App\Domain\Admissions\CalculScore;
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
        $this->autoriser(Demande::findOrFail($demande), 'admissions.consulter');

        $this->scoreDe = $demande;
        $this->dialogue = 'score';
    }

    /** @return array<string, mixed>|null */
    protected function detailScore(): ?array
    {
        if ($this->dialogue !== 'score' || $this->scoreDe === null) {
            return null;
        }

        $demande = Demande::with('campagne.criteres.typePiece', 'pieces', 'stagiaire')->findOrFail($this->scoreDe);
        $this->autoriser($demande, 'admissions.consulter');
        $evaluation = app(CalculScore::class)->evaluer($demande);

        return [
            'demande' => $demande,
            'recalcule' => $evaluation['total'],
            'lignes' => $evaluation['lignes'],
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'decisions' => DecisionAdmission::where('demande_id', $demande->id)->with('validateur:id,nom,prenom')->orderByDesc('date_validation')->get(),
        ];
    }
}
