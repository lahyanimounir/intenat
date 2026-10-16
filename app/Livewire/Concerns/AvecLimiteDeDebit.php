<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Limite de débit des écrans publics (connexion, création de compte, récupération).
 *
 * Trois plafonds s'empilent, comme sur l'ancienne interface :
 *  - par cible et par poste : 5 essais par minute sur un même compte ;
 *  - par cible, tous postes confondus : freine une attaque répartie sur de nombreuses adresses IP ;
 *  - par poste : plafond large, car tout un établissement sort souvent par la même adresse IP.
 */
trait AvecLimiteDeDebit
{
    /** Refuse l'action si l'un des plafonds est atteint ; sinon, compte la tentative. */
    protected function limiter(string $action, ?string $cible, string $champ = 'email'): void
    {
        $ip = (string) request()->ip();
        $cible = $cible !== null && $cible !== '' ? hash('sha256', mb_strtolower(trim($cible))) : null;

        $plafonds = array_filter([
            $cible !== null ? ["{$action}|cible:{$cible}|{$ip}", 5, 60] : null,
            $cible !== null ? ["{$action}|partout:{$cible}", 30, 900] : null,
            ["{$action}|ip:{$ip}", 120, 60],
        ]);

        foreach ($plafonds as [$cle, $maximum]) {
            if (RateLimiter::tooManyAttempts($cle, $maximum)) {
                $attente = RateLimiter::availableIn($cle);

                throw ValidationException::withMessages([
                    $champ => "Trop de tentatives. Patientez {$attente} seconde".($attente > 1 ? 's' : '').' avant de réessayer.',
                ]);
            }
        }

        foreach ($plafonds as [$cle, , $duree]) {
            RateLimiter::hit($cle, $duree);
        }
    }
}
