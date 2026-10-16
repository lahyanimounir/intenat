<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Residence\VieInternat;
use App\Livewire\PageStagiaire;
use App\Models\DemandeSortie;
use Livewire\Attributes\Title;

/** Demandes de sortie du résident : dépôt, puis décision du gestionnaire. */
#[Title('Sorties')]
class Sorties extends PageStagiaire
{
    public string $date_depart = '';

    public string $date_retour = '';

    public string $motif = '';

    public function deposer(VieInternat $vie): void
    {
        $valides = $this->validate([
            'date_depart' => ['required', 'date', 'after_or_equal:now'],
            'date_retour' => ['required', 'date', 'after:date_depart'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'date_depart.after_or_equal' => 'Le départ ne peut pas être dans le passé.',
            'date_retour.after' => 'Le retour doit suivre le départ.',
        ], ['date_depart' => 'départ', 'date_retour' => 'retour', 'motif' => 'motif']);

        if ($this->executer(fn () => $vie->deposerSortie($this->stagiaire(), $valides), 'Demande de sortie transmise au gestionnaire.')) {
            $this->reset('date_depart', 'date_retour', 'motif');
            $this->fermerDialogue();
        }
    }

    public function render()
    {
        $stagiaire = $this->stagiaire();

        return view('livewire.stagiaire.sorties', [
            'resident' => $stagiaire->affectationActive() !== null,
            'sorties' => DemandeSortie::whereHas('affectation.demande', fn ($q) => $q->where('stagiaire_id', $stagiaire->id))
                ->latest('date_depart')->limit(50)->get(),
        ]);
    }
}
