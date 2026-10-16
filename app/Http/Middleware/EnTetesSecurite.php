<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité des réponses de l'API. La politique de contenu du SPA
 * lui-même est posée par Nginx (voir deploy/nginx.conf).
 */
class EnTetesSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $reponse */
        $reponse = $next($request);

        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('X-Frame-Options', 'DENY');
        $reponse->headers->set('Referrer-Policy', 'same-origin');
        $reponse->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (! $reponse->headers->has('Cache-Control') || $reponse->headers->get('Cache-Control') === 'no-cache, private') {
            $reponse->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $reponse->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $reponse;
    }
}
