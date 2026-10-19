<?php

namespace App\Livewire\Concerns;

use App\Domain\Admissions\CalculScore;
use App\Domain\Admissions\NotationManuelle;
use App\Domain\Shared\Parametres;
use App\Models\Critere;
use App\Models\DecisionAdmission;
use App\Models\Demande;
use Livewire\Attributes\Locked;

/**
 * Fenêtre « Détail du score » d'un dossier : chaque critère, la déclaration, la
 * pièce et les points. Réservée aux comptes qui voient le classement, dans leur
 * périmètre. Le gestionnaire, qui appelle les candidats de la liste d'attente, la consulte
 * en lecture seule pour les dossiers de son internat. Seul le compte qui peut noter (chef
 * de pôle, directeur) y rectifie la note de chaque critère, avant comme après la délibération.
 */
trait AvecDetailScore
{
    #[Locked]
    public ?int $scoreDe = null;

    /** Saisie des notes par critère dans le détail du score : id de critère => points. */
    public array $notes = [];

    public function voirScore(int $demande): void
    {
        $modele = Demande::findOrFail($demande);
        $this->autoriserConsultationScore($modele);

        $this->scoreDe = $demande;
        $this->dialogue = 'score';
        $this->primerNotes($modele);
    }

    /**
     * Consulter le détail du score : comptes qui voient le classement, ou gestionnaire qui appelle les
     * candidats de la liste d'attente ; toujours dans le périmètre du compte (son internat).
     */
    protected function autoriserConsultationScore(Demande $demande): void
    {
        if ($this->utilisateur()->can('admissions.consulter')) {
            $this->autoriser($demande, 'admissions.consulter');

            return;
        }

        $this->autoriser($demande, 'admissions.promouvoir');

        // Règle « score masqué au gestionnaire » : il instruit sans connaître la valeur des dossiers ; il la
        // découvre une fois l'instruction terminée, c'est-à-dire la campagne délibérée.
        abort_if(app(Parametres::class)->vrai('instruction.masquer_score_gestionnaire') && ! $demande->campagne->estDeliberee(), 403);
    }

    /** Amorce les champs de saisie avec les points actuels de chaque critère. */
    protected function primerNotes(Demande $demande): void
    {
        $this->notes = collect(app(CalculScore::class)->evaluer($demande)['lignes'])
            ->mapWithKeys(fn (array $ligne) => [$ligne['critere']->id => $ligne['points']])
            ->all();
    }

    /** Le compte peut-il rectifier les notes de ce dossier ? Tout dossier noté, quel que soit son statut. */
    protected function notesAjustables(Demande $demande): bool
    {
        return $this->utilisateur()->can('admissions.noter') && NotationManuelle::ajustable($demande);
    }

    /** Rectifie la note d'un critère pour le dossier dont le détail est ouvert. */
    public function enregistrerNote(int $critere, NotationManuelle $notation): void
    {
        if ($this->scoreDe === null) {
            return;
        }

        $demande = Demande::with('campagne')->findOrFail($this->scoreDe);
        $this->autoriser($demande, 'admissions.noter');
        $modeleCritere = Critere::where('campagne_id', $demande->campagne_id)->findOrFail($critere);

        $valide = $this->validate([
            "notes.$critere" => ['required', 'integer', 'min:0', 'max:'.(int) $modeleCritere->points],
        ], [], ["notes.$critere" => 'note']);

        $this->executer(function () use ($notation, $demande, $modeleCritere, $valide, $critere) {
            $demande = $notation->fixer($demande, $modeleCritere, (int) $valide['notes'][$critere], null, $this->utilisateur());
            $this->primerNotes($demande->load('campagne.criteres.typePiece', 'pieces', 'stagiaire', 'notesManuelles'));
            $this->message($this->messageApresNote($demande, 'Note mise à jour'));
        });
    }

    /** Rétablit la note automatique d'un critère. */
    public function retablirNote(int $critere, NotationManuelle $notation): void
    {
        if ($this->scoreDe === null) {
            return;
        }

        $demande = Demande::with('campagne')->findOrFail($this->scoreDe);
        $this->autoriser($demande, 'admissions.noter');
        $modeleCritere = Critere::where('campagne_id', $demande->campagne_id)->findOrFail($critere);

        $this->executer(function () use ($notation, $demande, $modeleCritere) {
            $demande = $notation->retablir($demande, $modeleCritere, $this->utilisateur());
            $this->primerNotes($demande->load('campagne.criteres.typePiece', 'pieces', 'stagiaire', 'notesManuelles'));
            $this->message($this->messageApresNote($demande, 'Note automatique rétablie'));
        });
    }

    private function messageApresNote(Demande $demande, string $debut): string
    {
        return $demande->campagne->estDeliberee()
            ? "{$debut} : score et rang recalculés. L’admission, la décision de délibération et l’affectation sont inchangées."
            : "{$debut} : le score et le classement ont été recalculés.";
    }

    /** @return array<string, mixed>|null */
    protected function detailScore(): ?array
    {
        if ($this->dialogue !== 'score' || $this->scoreDe === null) {
            return null;
        }

        $demande = Demande::with('campagne.criteres.typePiece', 'pieces', 'stagiaire', 'notesManuelles.auteur:id,nom,prenom')->findOrFail($this->scoreDe);
        $this->autoriserConsultationScore($demande);
        $evaluation = app(CalculScore::class)->evaluer($demande);

        return [
            'demande' => $demande,
            'recalcule' => $evaluation['total'],
            'lignes' => $evaluation['lignes'],
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'ajustable' => $this->notesAjustables($demande),
            'lectureSeule' => ! $this->utilisateur()->can('admissions.noter'),
            'deliberee' => $demande->campagne->estDeliberee(),
            'notesManuelles' => $demande->notesManuelles->keyBy('critere_id'),
            'decisions' => DecisionAdmission::where('demande_id', $demande->id)->with('validateur:id,nom,prenom')->orderByDesc('date_validation')->get(),
        ];
    }
}
