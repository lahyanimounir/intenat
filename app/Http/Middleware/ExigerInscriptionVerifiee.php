<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les fonctionnalités du séjour (dossier, hébergement, paiements, repas,
 * sorties, réclamations…) ne s'ouvrent qu'une fois l'inscription en formation
 * vérifiée par l'agent habilité. Tant qu'elle ne l'est pas, le candidat est
 * ramené à son écran d'inscription, où il suit son statut et corrige au besoin
 * sa déclaration. Ce verrou double le masquage du menu : il interdit l'accès
 * direct par URL.
 */
class ExigerInscriptionVerifiee
{
    public function handle(Request $request, Closure $next): Response
    {
        $stagiaire = $request->user('stagiaire');

        if ($stagiaire instanceof Stagiaire && ! $stagiaire->inscriptionVerifiee()) {
            return redirect()->route('stagiaire.inscription');
        }

        return $next($request);
    }
}
