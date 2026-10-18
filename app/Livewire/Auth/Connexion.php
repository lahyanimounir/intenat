<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\Connexion as OuvertureDeSession;
use App\Domain\Shared\RegleMetierException;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Accueil de l'espace stagiaires et candidats (frontend, « / ») : connexion des stagiaires qui
 * ont un compte, accès à l'inscription et à la récupération du mot de passe. Le personnel a sa
 * propre entrée (ConnexionPersonnel, « /backend ») : aucun choix d'espace n'est à faire ici.
 */
#[Layout('layouts.auth')]
#[Title('Connexion')]
class Connexion extends Component
{
    use AvecLimiteDeDebit;

    public string $email = '';

    public string $mot_de_passe = '';

    public ?string $refus = null;

    /** Espace ouvert par cette page : il fixe la garde de session et l'accueil après connexion. */
    protected function espace(): string
    {
        return 'stagiaire';
    }

    public function connecter(OuvertureDeSession $connexion): void
    {
        $this->refus = null;
        $stagiaire = $this->espace() === 'stagiaire';

        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ], attributes: ['email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe']);

        $this->limiter('connexion:'.$this->espace(), $this->email);

        try {
            $stagiaire
                ? $connexion->stagiaire($this->email, $this->mot_de_passe)
                : $connexion->personnel($this->email, $this->mot_de_passe);
        } catch (RegleMetierException $regle) {
            // Compte suspendu : annoncé après un mot de passe correct seulement.
            $this->reset('mot_de_passe');
            $this->refus = $regle->getMessage();

            return;
        } catch (ValidationException $echec) {
            // Le mot de passe ne reste pas dans l'état du composant après une tentative manquée.
            $this->reset('mot_de_passe');

            throw $echec;
        }

        $accueil = $stagiaire ? route('stagiaire.accueil') : route('tableau-de-bord');

        // Retour à la page demandée avant la connexion, si elle appartient bien à l'espace du compte.
        $voulue = (string) session()->pull('url.intended', '');
        $chemin = (string) parse_url($voulue, PHP_URL_PATH);
        $cible = $voulue !== '' && str_starts_with($chemin, '/stagiaire') === $stagiaire ? $voulue : $accueil;

        $this->redirect($cible, navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.connexion');
    }
}
