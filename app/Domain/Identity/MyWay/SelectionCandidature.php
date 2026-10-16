<?php

namespace App\Domain\Identity\MyWay;

/**
 * Choix de la candidature qui fonde l'accès à INTERNAT.
 *
 * Règle documentée : il faut au moins une candidature « Inscrit Payé ». Quand
 * il y en a plusieurs, la documentation ne dit pas laquelle retenir — et un
 * choix arbitraire rattacherait le stagiaire à un établissement ou à une
 * filière qui n'est peut-être plus la sienne. La sélection est donc prudente :
 *
 *  1. seules les candidatures au statut exigé comptent ;
 *  2. si toutes portent une année de formation, seules celles de l'année la
 *     plus récente sont gardées : une inscription d'une année passée n'ouvre
 *     pas de droit sur l'année en cours ;
 *  3. les doublons (même établissement, même filière) n'en font qu'une ;
 *  4. s'il en reste exactement une, elle est retenue ; s'il en reste plusieurs,
 *     la vérification est déclarée ambiguë et aucun compte n'est activé.
 */
final class SelectionCandidature
{
    public const AUCUNE = 'aucune';

    public const AMBIGUE = 'ambigue';

    /**
     * @return array{candidature: array{nom_efp: string, nom_filiere: string, statut: string, annee?: string|null}|null, motif: self::AUCUNE|self::AMBIGUE|null, eligibles: int}
     */
    public static function retenir(ProfilMyWay $profil, string $statutExige): array
    {
        $eligibles = $profil->candidaturesEligibles($statutExige);

        if ($eligibles === []) {
            return ['candidature' => null, 'motif' => self::AUCUNE, 'eligibles' => 0];
        }

        $annees = array_map(fn (array $c) => self::anneeDeDebut($c['annee'] ?? null), $eligibles);

        if (! in_array(null, $annees, true)) {
            $recente = max($annees);
            $eligibles = array_values(array_filter($eligibles, fn (array $c) => self::anneeDeDebut($c['annee'] ?? null) === $recente));
        }

        $distinctes = [];

        foreach ($eligibles as $candidature) {
            $cle = ProfilMyWay::normaliser($candidature['nom_efp']).'|'.ProfilMyWay::normaliser($candidature['nom_filiere']);
            $distinctes[$cle] ??= $candidature;
        }

        if (count($distinctes) > 1) {
            return ['candidature' => null, 'motif' => self::AMBIGUE, 'eligibles' => count($distinctes)];
        }

        return ['candidature' => array_values($distinctes)[0], 'motif' => null, 'eligibles' => 1];
    }

    /** « 2026-2027 », « 2026/2027 » ou « 2026 » → 2026 ; null si l'année n'est pas lisible. */
    private static function anneeDeDebut(?string $annee): ?int
    {
        return $annee !== null && preg_match('/\b(20\d{2})\b/', $annee, $trouve) ? (int) $trouve[1] : null;
    }
}
