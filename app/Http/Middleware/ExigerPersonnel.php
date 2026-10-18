<?php

namespace App\Http\Middleware;

use App\Models\Utilisateur;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Réserve une route aux comptes du personnel actifs. */
class ExigerPersonnel
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof Utilisateur) {
            throw new AuthorizationException('Cet espace est réservé au personnel.');
        }

        if (! $utilisateur->actif) {
            // Action d'un écran déjà ouvert : la page se recharge, et c'est la requête suivante qui ferme la session et l'explique.
            abort_if($request->hasHeader('X-Livewire'), 401);

            Auth::guard('web')->logout();

            if ($request->expectsJson()) {
                throw new AuthorizationException('Ce compte est désactivé.');
            }

            // À l'écran : la session est fermée et la personne revient à la connexion, avec l'explication.
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('backend.connexion')->with('danger', 'Ce compte est désactivé. Adressez-vous à l\'administrateur de l\'application.');
        }

        return $next($request);
    }
}
