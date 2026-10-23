<?php

namespace App\Livewire\Auth;

use App\Domain\Audit\Journal;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Remplacement obligatoire d'un mot de passe provisoire, à la première connexion qui suit une
 * réinitialisation par un responsable. Le nouveau mot de passe doit différer du provisoire.
 */
#[Layout('layouts.auth')]
#[Title('Nouveau mot de passe')]
class ChangementMotDePasse extends Component
{
    use AvecLimiteDeDebit;

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    private function compte(): Utilisateur|Stagiaire
    {
        $compte = auth('web')->user() ?? auth('stagiaire')->user();
        abort_unless($compte instanceof Utilisateur || $compte instanceof Stagiaire, 403);

        return $compte;
    }

    private function accueil(Utilisateur|Stagiaire $compte): string
    {
        return $compte instanceof Stagiaire ? route('stagiaire.accueil') : route('tableau-de-bord');
    }

    public function mount(): void
    {
        $compte = $this->compte();

        if (! $compte->mot_de_passe_provisoire) {
            $this->redirect($this->accueil($compte), navigate: false);
        }
    }

    public function enregistrer(Journal $journal): void
    {
        $compte = $this->compte();

        $this->validate([
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
        ], ['mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.'], ['mot_de_passe' => 'nouveau mot de passe']);

        $this->limiter('changement-mot-de-passe', $compte->email, 'mot_de_passe');

        if (Hash::check($this->mot_de_passe, (string) $compte->mot_de_passe_hash)) {
            $this->reset('mot_de_passe', 'mot_de_passe_confirmation');

            throw ValidationException::withMessages(['mot_de_passe' => 'Choisissez un mot de passe différent du mot de passe provisoire.']);
        }

        $compte->forceFill([
            'mot_de_passe_hash' => $this->mot_de_passe,
            'mot_de_passe_provisoire' => false,
            'remember_token' => Str::random(60),
        ] + ($compte instanceof Stagiaire ? ['mot_de_passe_modifie_le' => now()] : []))->save();

        $journal->consigner('auth.mot_de_passe_provisoire_remplace', $compte, acteur: $compte);
        $this->reset('mot_de_passe', 'mot_de_passe_confirmation');

        session()->flash('succes', 'Votre mot de passe a été enregistré.');
        $this->redirect($this->accueil($compte), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.changement-mot-de-passe', ['compte' => $this->compte()]);
    }
}
