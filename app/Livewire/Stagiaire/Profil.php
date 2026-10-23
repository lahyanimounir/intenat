<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Audit\Journal;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Livewire\PageStagiaire;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;

/**
 * « Mon profil » : le compte du stagiaire. L'identité et la formation se
 * déclarent dans « Mon inscription » ; ici, le stagiaire relit son compte et
 * change son mot de passe.
 */
#[Title('Mon profil')]
class Profil extends PageStagiaire
{
    use AvecLimiteDeDebit;

    public string $actuel = '';

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    public function changerMotDePasse(Journal $journal): void
    {
        $stagiaire = $this->stagiaire();

        $this->validate([
            'actuel' => ['required', 'string', 'max:200'],
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', 'different:actuel', Password::defaults()],
        ], [
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'mot_de_passe.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ], ['actuel' => 'mot de passe actuel', 'mot_de_passe' => 'nouveau mot de passe']);

        $this->limiter('changement-mot-de-passe', $stagiaire->email, 'actuel');

        if (! Hash::check($this->actuel, (string) $stagiaire->mot_de_passe_hash)) {
            $this->reset('actuel');

            throw ValidationException::withMessages(['actuel' => 'Mot de passe actuel incorrect.']);
        }

        $stagiaire->forceFill([
            'mot_de_passe_hash' => $this->mot_de_passe,
            'mot_de_passe_modifie_le' => now(),
            'mot_de_passe_provisoire' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $journal->consigner('auth.mot_de_passe_modifie', $stagiaire, acteur: $stagiaire);

        $this->reset('actuel', 'mot_de_passe', 'mot_de_passe_confirmation');
        $this->message('Votre mot de passe a été modifié.');
    }

    public function render()
    {
        return view('livewire.stagiaire.profil', ['stagiaire' => $this->stagiaire()->loadMissing('etablissement')]);
    }
}
