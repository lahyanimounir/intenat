<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un mot de passe fixé par un responsable est provisoire : tant que son titulaire ne l'a pas remplacé,
 * tout le ramène à l'écran de changement du mot de passe, dans l'un et l'autre espace.
 */
class ExigerMotDePasseDefinitif
{
    public function handle(Request $request, Closure $next): Response
    {
        // Écran (garde de session) ou API (jeton) : le compte connecté, quelle que soit la garde.
        $compte = $request->user() ?? $request->user('web') ?? $request->user('stagiaire');

        if (($compte instanceof Utilisateur || $compte instanceof Stagiaire) && $compte->mot_de_passe_provisoire) {
            return $request->expectsJson() || $request->hasHeader('X-Livewire')
                ? response()->json(['message' => 'Changez votre mot de passe provisoire avant de continuer.'], 403)
                : redirect()->route('mot-de-passe.changement');
        }

        return $next($request);
    }
}
