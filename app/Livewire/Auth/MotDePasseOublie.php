<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\RecuperationCompte;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Demande d'un lien de réinitialisation. La réponse est la même que l'adresse corresponde ou non à un compte. */
#[Layout('layouts.auth')]
#[Title('Mot de passe oublié')]
class MotDePasseOublie extends Component
{
    use AvecLimiteDeDebit;

    #[Url(except: 'personnel')]
    public string $espace = 'personnel';

    public string $email = '';

    public ?string $envoyeA = null;

    public function mount(): void
    {
        if (! in_array($this->espace, RecuperationCompte::ESPACES, true)) {
            $this->espace = 'personnel';
        }
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
