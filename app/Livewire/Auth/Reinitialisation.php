<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\RecuperationCompte;
use App\Domain\Shared\RegleMetierException;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use SensitiveParameter;

/**
 * Choix d'un nouveau mot de passe à partir du lien reçu par courriel.
 *
 * Le jeton voyage dans le fragment de l'adresse (après « # ») : le navigateur ne
 * le transmet à aucun serveur à l'ouverture de la page, et il n'apparaît dans
 * aucun journal d'accès. La vue le garde en mémoire et ne le remet au serveur
 * qu'avec le nouveau mot de passe.
 */
#[Layout('layouts.auth')]
#[Title('Nouveau mot de passe')]
class Reinitialisation extends Component
{
    use AvecLimiteDeDebit;

    public string $email = '';

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    public ?string $refus = null;

    /** Fixé par la route : « /reinitialisation » pour les stagiaires, « /backend/reinitialisation » pour le personnel. */
    #[Locked]
    public string $espace = 'stagiaire';

    public function mount(string $espace = 'stagiaire'): void
    {
        $this->espace = $espace === 'personnel' ? 'personnel' : 'stagiaire';
    }

    public function enregistrer(RecuperationCompte $recuperation, #[SensitiveParameter] string $jeton): void
    {
        $this->refus = null;
        $espace = $this->espace;

        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
        ], [
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ], ['email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe']);

        $this->limiter('reinitialisation', mb_substr($jeton, 0, 200) ?: $this->email);

        try {
            $recuperation->reinitialiser($espace, $this->email, mb_substr($jeton, 0, 200), $this->mot_de_passe);
        } catch (RegleMetierException $regle) {
            $this->reset('mot_de_passe', 'mot_de_passe_confirmation');
            $this->refus = $regle->getMessage();

            return;
        }

        session()->flash('succes', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');

        $this->redirect(route($espace === 'personnel' ? 'backend.connexion' : 'connexion'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.reinitialisation');
    }
}
