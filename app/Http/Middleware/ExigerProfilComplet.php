<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le reste de l'espace du stagiaire suppose une identité et une formation
 * déclarées : tant que le profil est incomplet, tout ramène à l'écran d'inscription.
 */
class ExigerProfilComplet
{
    public function handle(Request $request, Closure $next): Response
    {
        $stagiaire = $request->user('stagiaire');

        if ($stagiaire instanceof Stagiaire && ! $stagiaire->profilComplet()) {
            return redirect()->route('stagiaire.inscription');
        }

        return $next($request);
    }
}
