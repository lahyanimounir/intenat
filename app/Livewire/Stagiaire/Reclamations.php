<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Residence\VieInternat;
use App\Enums\DomaineReclamation;
use App\Livewire\PageStagiaire;
use App\Models\Reclamation;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;

/** Réclamations du stagiaire, orientées par domaine vers le bon responsable. */
#[Title('Réclamations')]
class Reclamations extends PageStagiaire
{
    public string $domaine = '';

    public string $description = '';

    public function deposer(VieInternat $vie): void
    {
        $valides = $this->validate([
            'domaine' => ['required', Rule::enum(DomaineReclamation::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ], attributes: ['domaine' => 'domaine', 'description' => 'description']);

        if ($this->executer(
            fn () => $vie->deposerReclamation($this->stagiaire(), DomaineReclamation::from($valides['domaine']), $valides['description']),
            'Réclamation transmise au responsable concerné.',
        )) {
            $this->reset('domaine', 'description');
            $this->fermerDialogue();
        }
    }

    public function render()
    {
        return view('livewire.stagiaire.reclamations', [
            'reclamations' => Reclamation::where('stagiaire_id', $this->stagiaire()->id)->latest('date_creation')->limit(50)->get(),
        ]);
    }
}
