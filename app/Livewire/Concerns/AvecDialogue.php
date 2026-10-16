<?php

namespace App\Livewire\Concerns;

/**
 * Une fenêtre modale ouverte à la fois, désignée par son nom. La vue ne rend la
 * fenêtre que si `$dialogue` porte son nom ; la fermer efface aussi les erreurs
 * de validation de la saisie abandonnée.
 */
trait AvecDialogue
{
    public ?string $dialogue = null;

    public function ouvrirDialogue(string $nom): void
    {
        $this->resetValidation();
        $this->dialogue = $nom;
    }

    public function fermerDialogue(): void
    {
        $this->resetValidation();
        $this->dialogue = null;
    }
}
