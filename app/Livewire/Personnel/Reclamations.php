<?php

namespace App\Livewire\Personnel;

use App\Domain\Residence\VieInternat;
use App\Enums\DomaineReclamation;
use App\Enums\StatutReclamation;
use App\Livewire\PagePersonnel;
use App\Models\Reclamation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Réclamations des stagiaires. Le domaine choisi au dépôt désigne le
 * responsable : hébergement → gestionnaire, restauration → responsable de la
 * restauration, sélection → chef de pôle. Chacun ne voit et ne traite que les
 * réclamations de son domaine, dans son périmètre.
 */
#[Title('Réclamations')]
class Reclamations extends PagePersonnel
{
    use WithPagination;

    private const PERMISSION = 'reclamations.traiter';

    #[Url(except: '')]
    public string $statut = '';

    #[Locked]
    public ?int $ligne = null;

    public string $reponse = '';

    public function mount(): void
    {
        $this->domaine();
    }

    public function updatedStatut(): void
    {
        $this->resetPage();
    }

    /** Domaine dont répond le rôle du compte ; un rôle sans domaine n'a pas accès à l'écran. */
    private function domaine(): DomaineReclamation
    {
        $this->exiger(self::PERMISSION);
        $domaine = VieInternat::domaineDuRole($this->utilisateur()->roleCode());
        abort_if($domaine === null, 403);

        return $domaine;
    }

    private function reclamation(int $id): Reclamation
    {
        $reclamation = Reclamation::findOrFail($id);
        $this->autoriser($reclamation, self::PERMISSION);
        abort_if($reclamation->domaine !== $this->domaine(), 403, 'Cette réclamation relève d\'un autre responsable.');

        return $reclamation;
    }

    public function prendreEnCharge(VieInternat $vie, int $id): void
    {
        $reclamation = $this->reclamation($id);

        $this->executer(fn () => $vie->traiterReclamation($reclamation, StatutReclamation::EnCours, null, $this->utilisateur()), 'Réclamation prise en charge.');
    }

    public function repondre(int $id): void
    {
        $this->ligne = $this->reclamation($id)->id;
        $this->reponse = '';
        $this->ouvrirDialogue('reponse');
    }

    public function clore(VieInternat $vie): void
    {
        $reclamation = $this->reclamation((int) $this->ligne);
        $reponse = $this->validate(['reponse' => ['required', 'string', 'min:5', 'max:2000']], attributes: ['reponse' => 'réponse'])['reponse'];

        if ($this->executer(fn () => $vie->traiterReclamation($reclamation, StatutReclamation::Traitee, trim($reponse), $this->utilisateur()), 'Réponse envoyée au stagiaire.')) {
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $domaine = $this->domaine();

        return view('livewire.personnel.reclamations', [
            'domaine' => $domaine,
            'reclamations' => Reclamation::visiblesPar($this->utilisateur())->where('domaine', $domaine->value)
                ->with('stagiaire:id,nom,prenom,cef', 'internat:id,nom', 'traitant:id,nom,prenom')
                ->when($this->statut !== '', fn (Builder $q) => $q->where('statut', $this->statut))
                ->orderByRaw('statut = ? ASC', [StatutReclamation::Traitee->value])->orderByDesc('date_creation')
                ->paginate(25),
            'enReponse' => $this->dialogue === 'reponse' && $this->ligne !== null ? Reclamation::with('stagiaire:id,nom,prenom')->find($this->ligne) : null,
        ]);
    }
}
