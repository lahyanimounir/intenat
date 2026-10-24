<?php

namespace App\Http\Middleware;

use App\Models\Stagiaire;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les services du séjour (hébergement, paiements, repas, sorties, réclamations) ne s'ouvrent
 * qu'au stagiaire à qui un lit a été affecté. Un dossier validé, et même une place confirmée,
 * ne suffisent pas : avant l'affectation, le stagiaire est ramené à son accueil.
 */
class ExigerLitAffecte
{
    public function handle(Request $request, Closure $next): Response
    {
        $stagiaire = $request->user('stagiaire');

        if ($stagiaire instanceof Stagiaire && ! $stagiaire->aUnLitAffecte()) {
            // Action d'un écran déjà ouvert : la page se recharge et la requête suivante redirige.
            abort_if($request->hasHeader('X-Livewire'), 403);

            return redirect()->route('stagiaire.accueil')
                ->with('info', 'Les services liés à l’hébergement seront accessibles dès qu’un lit vous aura été affecté à l’internat.');
        }

        return $next($request);
    }
}
