<?php

namespace App\Livewire\Concerns;

use App\Domain\Shared\RegleMetierException;
use Closure;

/**
 * Retour d'une action à l'écran : message éphémère en cas de succès, et règle
 * de gestion expliquée à la personne plutôt qu'une page d'erreur quand le
 * domaine refuse l'opération.
 */
trait AvecMessages
{
    protected function message(string $texte, string $ton = 'succes'): void
    {
        $this->dispatch('message', ton: $ton, texte: $texte);
    }

    /**
     * Exécute une action du domaine. Renvoie faux — après avoir affiché le motif —
     * si une règle de gestion s'y oppose ; les erreurs d'accès et de validation ne sont pas interceptées.
     */
    protected function executer(Closure $action, ?string $succes = null): bool
    {
        try {
            $action();
        } catch (RegleMetierException $regle) {
            $this->message($regle->getMessage(), 'danger');

            return false;
        }

        if ($succes !== null) {
            $this->message($succes);
        }

        return true;
    }
}
