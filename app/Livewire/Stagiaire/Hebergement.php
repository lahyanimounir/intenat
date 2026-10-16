<?php

namespace App\Livewire\Stagiaire;

use App\Livewire\PageStagiaire;
use Livewire\Attributes\Title;

/** Séjour du stagiaire : sa chambre, son lit, ses arrivées et départs. */
#[Title('Hébergement')]
class Hebergement extends PageStagiaire
{
    public function render()
    {
        $affectation = $this->stagiaire()->affectationActive();
        $affectation?->load('mouvements');

        return view('livewire.stagiaire.hebergement', ['affectation' => $affectation]);
    }
}
