<?php

namespace App\Support\Affichage;

/** Tracés des icônes de l'interface, lus une fois par requête dans `resources/icones.php`. */
final class Icones
{
    /** @var array<string, string>|null */
    private static ?array $traces = null;

    public static function trace(string $nom): string
    {
        self::$traces ??= require resource_path('icones.php');

        // Une icône inconnue ne casse pas l'écran : elle n'est simplement pas dessinée.
        return self::$traces[$nom] ?? '';
    }
}
