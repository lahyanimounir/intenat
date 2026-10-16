<?php

namespace App\Livewire\Auth;

use App\Domain\Identity\Connexion;
use App\Domain\Identity\InscriptionStagiaire;
use App\Domain\Shared\RegleMetierException;
use App\Livewire\Concerns\AvecLimiteDeDebit;
use App\Models\Stagiaire;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création du compte INTERNAT d'un stagiaire : adresse e-mail et mot de passe.
 *
 * Le compte ouvre l'espace du stagiaire, où il déclare ensuite son identité et sa
 * formation. Créer un compte ne prouve rien : ni l'adresse, même confirmée, ni
 * ce que le stagiaire déclarera ne valent inscription en formation tant qu'un
 * agent ne l'a pas vérifiée.
 */
#[Layout('layouts.auth', ['large' => true])]
#[Title('Créer mon compte')]
class Inscription extends Component
{
    use AvecLimiteDeDebit;

    public string $email = '';

    public string $mot_de_passe = '';

    public string $mot_de_passe_confirmation = '';

    public function creer(InscriptionStagiaire $inscription, Connexion $connexion): void
    {
        $this->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
        ], [
            'mot_de_passe.confirmed' => 'Les deux mots de passe ne correspondent pas.',
        ], ['email' => 'adresse e-mail', 'mot_de_passe' => 'mot de passe']);

        $this->limiter('inscription', $this->email);

        $local = Str::before(mb_strtolower($this->email), '@');

        if (mb_strlen($local) >= 4 && Str::contains(mb_strtolower($this->mot_de_passe), $local)) {
            throw ValidationException::withMessages(['mot_de_passe' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.']);
        }

        // Une adresse déjà prise reçoit le même refus qu'une adresse inutilisable : rien n'est dit du compte existant.
        if (Stagiaire::where('email', mb_strtolower(trim($this->email)))->exists()) {
            $this->reset('mot_de_passe', 'mot_de_passe_confirmation');

            throw ValidationException::withMessages(['email' => $inscription->adresseIndisponible()->getMessage()]);
        }

        try {
            $stagiaire = $inscription->creerCompte($this->email, $this->mot_de_passe);
        } catch (RegleMetierException $regle) {
            $this->reset('mot_de_passe', 'mot_de_passe_confirmation');

            throw ValidationException::withMessages(['email' => $regle->getMessage()]);
        }

        $connexion->ouvrirPourStagiaire($stagiaire);

        $this->redirect(route('stagiaire.inscription'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.inscription', ['adresseAVerifier' => Stagiaire::verificationAdresseExigee()]);
    }
}
