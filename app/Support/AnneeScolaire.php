<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Année scolaire, de septembre à juillet, écrite « 2026-2027 ». */
final class AnneeScolaire
{
    /** Mois à partir duquel les inscriptions portent sur l'année scolaire suivante. */
    private const MOIS_DE_BASCULE = 7;

    public static function courante(?CarbonInterface $date = null): string
    {
        $date ??= now();
        $debut = $date->month >= self::MOIS_DE_BASCULE ? $date->year : $date->year - 1;

        return $debut.'-'.($debut + 1);
    }

    /**
     * Années proposées à la déclaration : l'année en cours et la suivante.
     *
     * @return array<string, string>
     */
    public static function options(?CarbonInterface $date = null): array
    {
        $courante = self::courante($date);
        $debut = (int) substr($courante, 0, 4) + 1;
        $suivante = $debut.'-'.($debut + 1);

        return [$courante => $courante, $suivante => $suivante];
    }
}
