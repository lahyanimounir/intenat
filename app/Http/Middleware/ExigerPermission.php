<?php

namespace App\Http\Middleware;

use App\Models\Utilisateur;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige l'une des permissions listées (séparées par « | »).
 * La permission ouvre l'action ; le périmètre des lignes est contrôlé ensuite.
 */
class ExigerPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof Utilisateur || ! $utilisateur->actif) {
            throw new AuthorizationException('Cet espace est réservé au personnel.');
        }

        foreach (explode('|', $permissions) as $permission) {
            if ($utilisateur->can($permission)) {
                return $next($request);
            }
        }

        throw new AuthorizationException;
    }
}
