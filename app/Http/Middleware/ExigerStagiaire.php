<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route aux stagiaires dont le compte INTERNAT est actif.
 * Un compte suspendu en cours de session est déconnecté à la requête suivante.
 */
class ExigerStagiaire
{
    public function handle(Request $request, Closure $next): Response
    {
        $stagiaire = $request->user();

        if (! $stagiaire instanceof Stagiaire) {
            throw new AuthorizationException('Cet espace est réservé aux stagiaires.');
        }

        if (! $stagiaire->estActif()) {
            // Action d'un écran déjà ouvert : la page se recharge, et c'est la requête suivante qui ferme la session et l'explique.
            abort_if($request->hasHeader('X-Livewire'), 401);

            Auth::guard('stagiaire')->logout();

            if ($request->expectsJson()) {
                throw new AuthorizationException('Ce compte n\'est pas actif.');
            }

            // À l'écran : la session est fermée et la personne revient à la connexion, avec l'explication.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('connexion')->with('danger', 'Ce compte n\'est pas actif. Adressez-vous à l\'administration de votre établissement.');
        }

        return $next($request);
    }
}
