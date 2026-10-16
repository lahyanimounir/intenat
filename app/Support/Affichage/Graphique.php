<?php

namespace App\Support\Affichage;

/**
 * Calculs des graphiques rendus par le serveur : aucune bibliothèque côté
 * navigateur. L'échelle est arrondie à des valeurs lisibles (1, 2, 5, 10…) et
 * chaque graphique porte un résumé textuel pour les lecteurs d'écran.
 */
final class Graphique
{
    /** Couleurs des séries, de la plus appuyée à la plus claire. */
    public const MARINE = '#183153';

    public const BLEU = '#7f9cc4';

    public const BLEU_CLAIR = '#9db4d2';

    public const PALE = '#c9d5e5';

    public const GRIS = '#dfe3ea';

    /**
     * Échelle verticale : plafond arrondi et graduations régulières.
     *
     * @return array{max: int, graduations: list<int>}
     */
    public static function echelle(int|float $maximum, int $graduations = 4): array
    {
        $maximum = max(1, (int) ceil($maximum));
        $brut = $maximum / $graduations;
        $ordre = 10 ** (int) floor(log10(max($brut, 1)));
        $pas = 1;

        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            $pas = (int) max(1, ceil($multiple * $ordre));

            if ($pas * $graduations >= $maximum) {
                break;
            }
        }

        $nombre = (int) ceil($maximum / $pas);

        return ['max' => $pas * $nombre, 'graduations' => array_map(fn (int $i) => $i * $pas, range(0, $nombre))];
    }

    /** Position d'une valeur sur l'échelle, en pourcentage de la hauteur. */
    public static function hauteur(int|float $valeur, int $max): float
    {
        return $max <= 0 ? 0.0 : round(min(100, max(0, $valeur / $max * 100)), 2);
    }
}
