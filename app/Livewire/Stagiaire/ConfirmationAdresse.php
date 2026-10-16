<?php

namespace App\Livewire\Stagiaire;

use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Livewire\PageStagiaire;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/** Écran d'attente tant que le stagiaire n'a pas confirmé son adresse e-mail. */
#[Layout('layouts.auth')]
#[Title('Confirmez votre adresse')]
class ConfirmationAdresse extends PageStagiaire
{
    use AvecLimiteDeDebit;

    public bool $renvoye = false;

    public function mount(): void
    {
        if (! $this->stagiaire()->doitVerifierSonAdresse()) {
            $this->redirect(route('stagiaire.inscription'), navigate: false);
        }
    }

    public function renvoyer(): void
    {
        $stagiaire = $this->stagiaire();
        $this->limiter('renvoi-adresse', $stagiaire->email, 'renvoi');

        if ($stagiaire->doitVerifierSonAdresse()) {
            $stagiaire->envoyerVerificationAdresse();
        }

        $this->renvoye = true;
    }

    public function render()
    {
        return view('livewire.stagiaire.confirmation-adresse', ['email' => $this->stagiaire()->email]);
    }
}
