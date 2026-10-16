<?php

namespace App\Enums\Concerns;

/**
 * Comportement commun des énumérations métier : valeurs stockées en base
 * et libellés français exposés à l'interface.
 */
trait AvecLibelle
{
    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{valeur: string, libelle: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $cas) => ['valeur' => $cas->value, 'libelle' => $cas->libelle()],
            self::cases(),
        );
    }
}
