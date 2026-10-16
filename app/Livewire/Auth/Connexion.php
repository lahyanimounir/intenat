<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\Connexion as OuvertureDeSession;
use App\Domain\Shared\RegleMetierException;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Models\Utilisateur;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Connexion du personnel et des stagiaires : un écran, deux espaces, deux gardes de session. */
#[Layout('layouts.auth')]
#[Title('Connexion')]
class Connexion extends Component
{
    use AvecLimiteDeDebit;

    #[Url(except: 'personnel')]
    public string $espace = 'personnel';

    public string $email = '';

    public string $mot_de_passe = '';

    public ?string $refus = null;

    public function mount(): void
    {
        if (! in_array($this->espace, ['personnel', 'stagiaire'], true)) {
            $this->espace = 'personnel';
        }
    }

    public function choisir(string $espace): void
    {
        $this->espace = $espace === 'stagiaire' ? 'stagiaire' : 'personnel';
        $this->reset('mot_de_passe', 'refus');
        $this->resetValidation();
    }

    public function connecter(OuvertureDeSession $connexion): void
    {
        $this->refus = null;

        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ], attributes: ['email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe']);

        $this->limiter('connexion:'.$this->espace, $this->email);

        try {
            $this->espace === 'stagiaire'
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

        $accueil = $this->espace === 'stagiaire' ? route('stagiaire.accueil') : route('tableau-de-bord');

        // Retour à la page demandée avant la connexion, si elle appartient bien à l'espace du compte.
        $voulue = (string) session()->pull('url.intended', '');
        $chemin = (string) parse_url($voulue, PHP_URL_PATH);
        $cible = $voulue !== '' && str_starts_with($chemin, '/stagiaire') === ($this->espace === 'stagiaire') ? $voulue : $accueil;

        $this->redirect($cible, navigate: false);
    }

    /** Comptes de démonstration, affichés en mode démonstration uniquement. */
    private function comptesDeDemonstration(): Collection
    {
        if (! config('internat.mode_demo') || app()->isProduction()) {
            return collect();
        }

        return Utilisateur::query()->with('roles')->where('actif', true)->where('email', 'like', '%@internat.test')
            ->orderBy('id')->limit(12)->get()
            ->map(fn (Utilisateur $u) => ['role' => (string) $u->roleCode()?->libelle(), 'email' => $u->email]);
    }

    public function render()
    {
        return view('livewire.auth.connexion', ['demonstration' => $this->comptesDeDemonstration()]);
    }
}
