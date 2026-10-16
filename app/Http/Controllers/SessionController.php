<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Connexion;
use App\Domain\Identity\InscriptionStagiaire;
use App\Models\Stagiaire;
use Illuminate\Http\RedirectResponse;

/** Actions de session qui ne relèvent pas d'un écran : déconnexion, et lien de confirmation de l'adresse reçu par courriel. */
class SessionController extends Controller
{
    public function deconnecter(Connexion $connexion): RedirectResponse
    {
        $espace = auth('stagiaire')->check() ? 'stagiaire' : 'personnel';
        $connexion->fermer();

        return redirect()->route('connexion', $espace === 'stagiaire' ? ['espace' => 'stagiaire'] : []);
    }

    /**
     * Lien signé et limité dans le temps (middleware `signed`). L'empreinte de l'adresse le rend
     * caduc si l'adresse du compte a changé depuis l'envoi.
     */
    public function confirmerAdresse(Stagiaire $stagiaire, string $empreinte, InscriptionStagiaire $inscription): RedirectResponse
    {
        abort_unless(hash_equals(sha1(mb_strtolower($stagiaire->email)), $empreinte), 403);

        $inscription->confirmerAdresse($stagiaire);

        $connecte = auth('stagiaire')->id() === $stagiaire->id;

        return redirect()
            ->route($connecte ? 'stagiaire.inscription' : 'connexion', $connecte ? [] : ['espace' => 'stagiaire'])
            ->with('succes', 'Votre adresse e-mail est confirmée.');
    }
}
