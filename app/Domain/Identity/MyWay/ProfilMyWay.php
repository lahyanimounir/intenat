<?php

namespace App\Domain\Identity\MyWay;

/**
 * Données reprises du fournisseur d'identité : uniquement les informations
 * nécessaires (Nom EFP, Nom Filière, CEF, Nom, Prénom, Email, CNI, Tél, Ville),
 * le Code EFP étant retrouvé localement à partir du Nom EFP.
 *
 * Une information que le fournisseur n'a pas communiquée vaut `null` : elle
 * n'est jamais remplacée par une valeur supposée. C'est le service de
 * vérification qui décide si ce qui manque empêche l'activation.
 */
final readonly class ProfilMyWay
{
    /**
     * @param  list<array{nom_efp: string, nom_filiere: string, statut: string, annee?: string|null}>  $candidatures
     * @param  array<string, string>  $provenance  Pour une lecture réelle : d'où vient chaque champ (réponse et chemin), sans aucune valeur.
     */
    public function __construct(
        public ?string $cef,
        public ?string $nom,
        public ?string $prenom,
        public string $email,
        public ?string $cni,
        public ?string $tel,
        public ?string $ville,
        public array $candidatures,
        public array $provenance = [],
    ) {}

    /**
     * Candidatures au statut exigé, dans l'ordre où le fournisseur les a renvoyées.
     *
     * @return list<array{nom_efp: string, nom_filiere: string, statut: string, annee?: string|null}>
     */
    public function candidaturesEligibles(string $statutExige): array
    {
        $attendu = self::normaliser($statutExige);

        return array_values(array_filter(
            $this->candidatures,
            fn (array $candidature) => self::normaliser($candidature['statut']) === $attendu,
        ));
    }

    /**
     * Première candidature au statut exigé, ou null si le stagiaire n'est pas éligible.
     * Ne dit pas laquelle retenir quand il y en a plusieurs : voir SelectionCandidature.
     *
     * @return array{nom_efp: string, nom_filiere: string, statut: string, annee?: string|null}|null
     */
    public function candidatureEligible(string $statutExige): ?array
    {
        return $this->candidaturesEligibles($statutExige)[0] ?? null;
    }

    /**
     * Comparaison tolérante à la casse, aux accents, aux espaces et aux séparateurs :
     * « Inscrit Payé », « inscrit payé » et le code « inscrit_paye » désignent le même statut.
     */
    public static function normaliser(string $valeur): string
    {
        $sansAccents = strtr(mb_strtolower(trim($valeur)), [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => "'",
            '_' => ' ', '-' => ' ',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', $sansAccents));
    }
}
