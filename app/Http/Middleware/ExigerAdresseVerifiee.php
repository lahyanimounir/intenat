<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quand la confirmation de l'adresse e-mail est exigée (serveur d'envoi réel
 * configuré), le stagiaire qui ne l'a pas encore confirmée est ramené à l'écran
 * qui le lui demande. Une adresse confirmée prouve qu'elle lui appartient — pas
 * qu'il est inscrit en formation.
 */
class ExigerAdresseVerifiee
{
    public function handle(Request $request, Closure $next): Response
    {
        $stagiaire = $request->user('stagiaire');

        if ($stagiaire instanceof Stagiaire && $stagiaire->doitVerifierSonAdresse()) {
            return redirect()->route('stagiaire.adresse');
        }

        return $next($request);
    }
}
