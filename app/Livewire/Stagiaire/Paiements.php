<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Payments\SuiviPaiements;
use App\Livewire\PageStagiaire;
use App\Models\Paiement;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

/**
 * Paiement de l'hébergement, mois par mois : le stagiaire paie directement et dépose son justificatif,
 * que le gestionnaire vérifie. Aucune autorisation préalable.
 * reçu par le stagiaire, contrôle par le gestionnaire. Sans rapport avec les
 * frais de scolarité, vérifiés à l'inscription.
 */
#[Title('Paiements')]
class Paiements extends PageStagiaire
{
    use WithFileUploads;

    /** Reçus en cours de dépôt, par identifiant de paiement. */
    public array $recus = [];

    #[Locked]
    public ?int $apercu = null;

    /** Paiement du stagiaire connecté — jamais celui d'un autre. */
    private function paiement(int $id): Paiement
    {
        return Paiement::whereHas('demande', fn ($q) => $q->where('stagiaire_id', $this->stagiaire()->id))->findOrFail($id);
    }

    public function updatedRecus(mixed $fichier, string $cle): void
    {
        if ($fichier === null) {
            return;
        }

        $paiement = $this->paiement((int) $cle);
        $this->executer(fn () => app(SuiviPaiements::class)->deposerRecu($paiement, $fichier), 'Justificatif déposé. Il sera vérifié par le gestionnaire.');

        unset($this->recus[$cle]);
    }

    public function voirRecu(int $id): void
    {
        abort_if($this->paiement($id)->recu_fichier === null, 404);

        $this->apercu = $id;
        $this->ouvrirDialogue('recu');
    }

    public function fermerDialogue(): void
    {
        $this->apercu = null;
        $this->dialogue = null;
    }

    public function render()
    {
        $demande = $this->stagiaire()->demandeCourante();

        return view('livewire.stagiaire.paiements', [
            'demande' => $demande,
            'paiements' => $demande ? $demande->paiements()->orderByDesc('periode')->get() : collect(),
        ]);
    }
}
