<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\RecuperationCompte;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Demande d'un lien de réinitialisation. La réponse est la même que l'adresse corresponde ou non à un compte. */
#[Layout('layouts.auth')]
#[Title('Mot de passe oublié')]
class MotDePasseOublie extends Component
{
    use AvecLimiteDeDebit;

    /** Fixé par la route : « /mot-de-passe-oublie » pour les stagiaires, « /backend/mot-de-passe-oublie » pour le personnel. */
    #[Locked]
    public string $espace = 'stagiaire';

    public string $email = '';

    public ?string $envoyeA = null;

    public function mount(string $espace = 'stagiaire'): void
    {
        $this->espace = $espace === 'personnel' ? 'personnel' : 'stagiaire';
    }

    public function envoyer(RecuperationCompte $recuperation): void
    {
        $this->validate(['email' => ['required', 'email', 'max:190']], attributes: ['email' => 'adresse e-mail']);
        $this->limiter('mot-de-passe-oubli:'.$this->espace, $this->email);

        $recuperation->demander($this->espace, $this->email);

        $this->envoyeA = $this->email;
    }

    public function render()
    {
        return view('livewire.auth.mot-de-passe-oublie');
    }
}
