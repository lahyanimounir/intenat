<?php

namespace App\Enums;

use App\Enums\Concerns\AvecLibelle;

/**
 * Origine d'une décision d'admission.
 */
enum OrigineDecision: string
{
    use AvecLibelle;

    case SelectionInitiale = 'selection_initiale';
    case ListeAttente = 'liste_attente';

    public function libelle(): string
    {
        return match ($this) {
            self::SelectionInitiale => 'Sélection initiale',
            self::ListeAttente => 'Liste d\'attente (place libérée)',
        };
    }
}
