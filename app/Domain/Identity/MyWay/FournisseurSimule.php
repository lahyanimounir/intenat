<?php

namespace App\Domain\Identity\MyWay;

use App\Enums\MethodeVerification;
use SensitiveParameter;

/**
 * Fournisseur simulé : profils entièrement synthétiques lus dans un fichier
 * local. Il ne contacte jamais MyWay et ne doit jamais être présenté comme
 * une connexion réelle. Refusé en production.
 *
 * Un profil du fichier peut être volontairement incomplet (CEF absent, année
 * de formation absente…) : le simulateur le renvoie tel quel, sans rien
 * combler, pour éprouver les refus du service de vérification.
 *
 * Adresses réservées pour exercer les cas d'erreur :
 *   indisponible@myway.test ......... fournisseur injoignable
 *   reponse-invalide@myway.test ..... réponse incohérente
 */
class FournisseurSimule implements VerifieParIdentifiants
{
    public function __construct(private readonly string $fichier) {}

    public function authentifier(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): ProfilMyWay
    {
        if (app()->isProduction()) {
            throw new FournisseurIndisponibleException('Le fournisseur simulé est interdit en production.');
        }

        $email = mb_strtolower(trim($email));

        if ($email === 'indisponible@myway.test') {
            throw new FournisseurIndisponibleException('Fournisseur simulé : indisponibilité provoquée.');
        }

        if ($email === 'reponse-invalide@myway.test') {
            throw new ReponseInattendueException('Fournisseur simulé : réponse incohérente provoquée.');
        }

        $profil = collect($this->profils())->first(fn (array $p) => mb_strtolower((string) ($p['email'] ?? '')) === $email);

        if ($profil === null || ! hash_equals((string) $profil['mot_de_passe'], $motDePasse)) {
            throw new IdentifiantsInvalidesException('Identifiants refusés par le fournisseur simulé.');
        }

        $texte = fn (string $cle): ?string => isset($profil[$cle]) && trim((string) $profil[$cle]) !== '' ? trim((string) $profil[$cle]) : null;

        return new ProfilMyWay(
            cef: $texte('cef'),
            nom: $texte('nom'),
            prenom: $texte('prenom'),
            email: (string) $profil['email'],
            cni: $texte('cni'),
            tel: $texte('tel'),
            ville: $texte('ville'),
            candidatures: array_values(array_filter(
                (array) ($profil['candidatures'] ?? []),
                fn ($c) => is_array($c) && isset($c['nom_efp'], $c['nom_filiere'], $c['statut']),
            )),
        );
    }

    public function nom(): string
    {
        return 'mock';
    }

    public function estSimule(): bool
    {
        return true;
    }

    public function methode(): MethodeVerification
    {
        return MethodeVerification::Identifiants;
    }

    /** @return list<array<string, mixed>> */
    private function profils(): array
    {
        if (! is_file($this->fichier)) {
            throw new FournisseurIndisponibleException('Fichier des profils simulés introuvable.');
        }

        $donnees = json_decode((string) file_get_contents($this->fichier), true);

        if (! is_array($donnees) || ! isset($donnees['profils'])) {
            throw new ReponseInattendueException('Fichier des profils simulés illisible.');
        }

        return $donnees['profils'];
    }
}
