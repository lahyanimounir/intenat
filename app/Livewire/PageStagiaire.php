<?php

namespace App\Livewire;

use App\Livewire\Concerns\AvecDialogue;
use App\Livewire\Concerns\AvecMessages;
use App\Models\Stagiaire;
use Closure;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Écran de l'espace du stagiaire. Tout ce qui y est lu ou modifié appartient au
 * compte connecté : aucun identifiant de stagiaire ne vient jamais de la requête.
 */
#[Layout('layouts.app')]
abstract class PageStagiaire extends Component
{
    use AvecDialogue;
    use AvecMessages {
        executer as private executerSansRelecture;
    }

    protected function stagiaire(): Stagiaire
    {
        $stagiaire = auth('stagiaire')->user();
        abort_unless($stagiaire instanceof Stagiaire && $stagiaire->estActif(), 403);

        return $stagiaire;
    }

    /**
     * Les services du domaine relisent et verrouillent la ligne du stagiaire : après une action, le compte
     * tenu par la session est relu pour que l'écran affiche ce qui vient d'être enregistré.
     */
    protected function executer(Closure $action, ?string $succes = null): bool
    {
        $reussie = $this->executerSansRelecture($action, $succes);

        auth('stagiaire')->user()?->refresh();

        return $reussie;
    }
}
