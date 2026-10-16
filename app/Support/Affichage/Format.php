<?php

namespace App\Support\Affichage;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Mise en forme française des dates, nombres et montants affichés à l'écran. */
final class Format
{
    private const VIDE = '—';

    public static function date(DateTimeInterface|string|null $valeur): string
    {
        return self::lire($valeur)?->format('d/m/Y') ?? self::VIDE;
    }

    public static function dateLongue(DateTimeInterface|string|null $valeur): string
    {
        return self::lire($valeur)?->translatedFormat('j F Y') ?? self::VIDE;
    }

    public static function dateHeure(DateTimeInterface|string|null $valeur): string
    {
        return self::lire($valeur)?->format('d/m/Y \à H:i') ?? self::VIDE;
    }

    public static function heure(DateTimeInterface|string|null $valeur): string
    {
        return self::lire($valeur)?->format('H:i') ?? self::VIDE;
    }

    /** « Lundi 14 octobre ». */
    public static function jour(DateTimeInterface|string|null $valeur): string
    {
        $date = self::lire($valeur);

        if ($date === null) {
            return self::VIDE;
        }

        $texte = $date->translatedFormat('l j F');

        return mb_strtoupper(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }

    /** « octobre 2026 », à partir d'une date ou d'un mois `AAAA-MM`. */
    public static function mois(DateTimeInterface|string|null $valeur): string
    {
        if (is_string($valeur) && preg_match('/^\d{4}-\d{2}$/', $valeur)) {
            $valeur .= '-01';
        }

        return self::lire($valeur)?->translatedFormat('F Y') ?? self::VIDE;
    }

    public static function nombre(int|float|null $valeur): string
    {
        if ($valeur === null) {
            return self::VIDE;
        }

        // Sans l'extension `intl`, absente de bien des hébergements : séparateurs français posés à la main.
        $decimales = is_int($valeur) || floor($valeur) == $valeur ? 0 : (round($valeur, 1) == $valeur ? 1 : 2);

        return number_format((float) $valeur, $decimales, ',', "\u{202F}");
    }

    public static function montant(int|float|string|null $valeur): string
    {
        return $valeur === null || $valeur === '' ? self::VIDE : number_format((float) $valeur, 2, ',', "\u{202F}").' DH';
    }

    public static function pourcentage(int|float|null $valeur): string
    {
        return $valeur === null ? self::VIDE : self::nombre($valeur).' %';
    }

    public static function taille(?int $octets): string
    {
        if ($octets === null) {
            return self::VIDE;
        }

        return $octets >= 1_048_576
            ? str_replace('.', ',', number_format($octets / 1_048_576, 1)).' Mo'
            : max(1, (int) round($octets / 1024)).' Ko';
    }

    /**
     * Temps restant avant une échéance, exprimé simplement.
     *
     * @return array{texte: string, depasse: bool, proche: bool}|null
     */
    public static function tempsRestant(DateTimeInterface|string|null $echeance): ?array
    {
        $date = self::lire($echeance);

        if ($date === null) {
            return null;
        }

        $minutes = (int) round(now()->diffInMinutes($date, false));

        if ($minutes <= 0) {
            return ['texte' => 'Échéance dépassée', 'depasse' => true, 'proche' => false];
        }

        $heures = intdiv($minutes, 60);
        $jours = intdiv($heures, 24);

        return [
            'texte' => match (true) {
                $jours >= 2 => "{$jours} jours restants",
                $heures >= 2 => "{$heures} heures restantes",
                default => max(1, $minutes).' min restantes',
            },
            'depasse' => false,
            'proche' => $heures < 48,
        ];
    }

    public static function initiales(?string $nom): string
    {
        return collect(preg_split('/\s+/', trim((string) $nom)) ?: [])
            ->filter()->take(2)
            ->map(fn (string $mot) => mb_strtoupper(mb_substr($mot, 0, 1)))
            ->implode('');
    }

    private static function lire(DateTimeInterface|string|null $valeur): ?CarbonInterface
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        return $valeur instanceof CarbonInterface ? $valeur : Carbon::parse($valeur);
    }
}
