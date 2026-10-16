<?php

namespace App\Livewire\Stagiaire;

use App\Livewire\PageStagiaire;
use App\Models\Document;
use Livewire\Attributes\Title;

/** Documents publiés par l'internat du stagiaire : règlement intérieur, notes, formulaires. */
#[Title('Documents utiles')]
class DocumentsUtiles extends PageStagiaire
{
    public function render()
    {
        $internat = $this->stagiaire()->internat();

        return view('livewire.stagiaire.documents-utiles', [
            'internat' => $internat,
            'documents' => $internat ? Document::where('internat_id', $internat->id)->latest('date_publication')->get() : collect(),
        ]);
    }
}
