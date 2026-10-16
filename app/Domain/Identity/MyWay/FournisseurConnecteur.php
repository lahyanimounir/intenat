<?php

namespace App\Domain\Identity\MyWay;

use App\Domain\Shared\RegleMetierException;
use App\Enums\MethodeVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Connexion RÉELLE à MyWay par le service `myway-connector` (navigateur piloté).
 *
 * OUTIL DE DIAGNOSTIC, pas un mécanisme de production : il sert à constater, sur
 * un compte dont le titulaire a donné son accord, ce que MyWay renvoie
 * réellement. Il ne doit jamais devenir un service public qui collecte et
 * rejoue les mots de passe MyWay des stagiaires.
 *
 * Périmètre volontairement étroit, en attendant l'accord de l'OFPPT (ADR 0006) :
 *  - seuls les comptes listés dans MYWAY_COMPTES_AUTORISES sont vérifiés — ceux
 *    dont le titulaire a donné son accord ; tout autre compte est refusé avant
 *    le moindre appel réseau ;
 *  - jamais en production (voir AppServiceProvider) ;
 *  - aucun repli : si le connecteur est absent, mal configuré, bloqué par
 *    MyWay ou incomplet, la tentative échoue. Ni simulation, ni valeur déduite.
 *
 * Le mot de passe traverse cette classe vers le connecteur et n'est ni
 * conservé, ni journalisé. Un profil n'est renvoyé que si le connecteur a
 * réellement lu, sur le site officiel, tous les champs exigés.
 */
class FournisseurConnecteur implements VerifieParIdentifiants
{
    private const LONGUEUR_MIN_JETON = 32;

    private const LIBELLES = [
        'cef' => 'CEF',
        'nom' => 'nom',
        'prenom' => 'prénom',
        'email' => 'adresse e-mail',
        'nom_efp' => 'établissement',
        'nom_filiere' => 'filière',
        'statut_candidature' => 'statut de candidature',
    ];

    /** @param  list<string>  $comptesAutorises  Adresses en minuscules. */
    public function __construct(
        private readonly string $url,
        #[SensitiveParameter] private readonly ?string $jeton,
        private readonly array $comptesAutorises,
        private readonly int $delaiSecondes,
    ) {}

    public static function depuisConfiguration(): self
    {
        $comptes = array_filter(array_map(
            fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) config('internat.myway.connecteur.comptes_autorises')),
        ));

        return new self(
            url: (string) config('internat.myway.connecteur.url'),
            jeton: config('internat.myway.connecteur.jeton'),
            comptesAutorises: array_values($comptes),
            delaiSecondes: max(5, (int) config('internat.myway.connecteur.delai_secondes')),
        );
    }

    public function authentifier(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): ProfilMyWay
    {
        $email = mb_strtolower(trim($email));

        // Défense en profondeur : AppServiceProvider ne lie déjà ce fournisseur ni en production ni en démonstration.
        if (app()->isProduction()) {
            throw new FournisseurIndisponibleException('Le connecteur MyWay n\'est pas autorisé en production.');
        }

        if (config('internat.mode_demo')) {
            throw new FournisseurIndisponibleException('Le connecteur MyWay est incompatible avec le mode démonstration.');
        }

        if (strlen((string) $this->jeton) < self::LONGUEUR_MIN_JETON || $this->url === '' || $this->comptesAutorises === []) {
            throw new FournisseurIndisponibleException(
                'Connecteur MyWay non configuré (MYWAY_CONNECTEUR_URL, MYWAY_CONNECTEUR_JETON, MYWAY_COMPTES_AUTORISES).'
            );
        }

        if (! in_array($email, $this->comptesAutorises, true)) {
            throw new CompteNonAutoriseException;
        }

        // Une session de navigateur dure plus longtemps qu'une requête ordinaire.
        if (! app()->runningInConsole()) {
            set_time_limit($this->delaiSecondes + 15);
        }

        try {
            $reponse = Http::baseUrl($this->url)
                ->withToken((string) $this->jeton)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout($this->delaiSecondes)
                ->post('/v1/verifications', ['email' => $email, 'mot_de_passe' => $motDePasse]);
        } catch (ConnectionException) {
            // L'exception d'origine n'est pas chaînée : elle n'a pas à voyager avec la requête.
            throw new FournisseurIndisponibleException('Connecteur MyWay injoignable ou délai dépassé.');
        }

        if ($reponse->successful()) {
            return $this->versProfil($reponse->json(), $email);
        }

        $code = (string) $reponse->json('code');
        $details = (array) $reponse->json('details');

        // Ni adresse, ni donnée de profil : seulement le code d'erreur et l'étape où la session s'est arrêtée.
        Log::warning('Connecteur MyWay : vérification non aboutie.', [
            'statut_http' => $reponse->status(),
            'code' => $code,
            'details' => array_intersect_key($details, array_flip(['etape', 'obstacle', 'indice', 'motif', 'statut', 'erreur_reseau'])),
        ]);

        throw $this->versException($code, $details);
    }

    public function nom(): string
    {
        return 'connecteur';
    }

    public function estSimule(): bool
    {
        return false;
    }

    public function methode(): MethodeVerification
    {
        return MethodeVerification::Identifiants;
    }

    /** @param  array<string, mixed>  $details */
    private function versException(string $code, array $details): \Throwable
    {
        return match ($code) {
            'identifiants_invalides' => new IdentifiantsInvalidesException('Identifiants refusés par MyWay.'),
            'compte_non_autorise' => new CompteNonAutoriseException,
            'verification_humaine_requise' => new VerificationRequiseException((string) ($details['obstacle'] ?? 'inconnu')),
            'reponse_inattendue' => new ReponseInattendueException('Le connecteur n\'a pas reconnu les pages de MyWay.'),
            'trop_de_tentatives', 'occupe' => new RegleMetierException(
                'Une vérification MyWay est déjà en cours ou trop récente. Patientez avant de réessayer.',
                'trop_de_tentatives',
                ['reessayer_dans_s' => (int) ($details['reessayer_dans_s'] ?? 30)],
                429,
            ),
            // Accès bloqué par MyWay, délai dépassé, navigateur absent, jeton refusé, panne : indisponible.
            default => new FournisseurIndisponibleException("Connecteur MyWay : {$code}."),
        };
    }

    private function versProfil(mixed $donnees, #[SensitiveParameter] string $email): ProfilMyWay
    {
        if (! is_array($donnees) || ($donnees['statut'] ?? null) !== 'verifie' || ! is_array($donnees['profil'] ?? null)) {
            throw new ReponseInattendueException('Réponse du connecteur MyWay illisible.');
        }

        // Séparation stricte : un résultat qui ne vient pas du site officiel (banc d'essai) n'entre jamais en base.
        if (($donnees['cible']['officielle'] ?? false) !== true) {
            throw new ReponseInattendueException('Le connecteur ne vise pas le site officiel MyWay : résultat refusé.');
        }

        $profil = $donnees['profil'];
        $texte = fn (mixed $valeur): ?string => is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;

        $candidatures = [];
        foreach (is_array($profil['candidatures'] ?? null) ? $profil['candidatures'] : [] as $candidature) {
            $ligne = [
                'nom_efp' => $texte($candidature['nom_efp'] ?? null),
                'nom_filiere' => $texte($candidature['nom_filiere'] ?? null),
                'statut' => $texte($candidature['statut'] ?? null),
            ];

            if (! in_array(null, $ligne, true)) {
                $candidatures[] = $ligne;
            }
        }

        // L'indicateur « complet » du connecteur n'est pas cru sur parole : chaque champ exigé est recontrôlé ici.
        $constates = array_keys(array_filter([
            'cef' => $texte($profil['cef'] ?? null) === null,
            'nom' => $texte($profil['nom'] ?? null) === null,
            'prenom' => $texte($profil['prenom'] ?? null) === null,
            'email' => $texte($profil['email'] ?? null) === null,
            'statut_candidature' => $candidatures === [],
        ]));
        $signales = array_filter((array) ($donnees['champs_manquants'] ?? []), 'is_string');
        $manquants = array_values(array_intersect(array_keys(self::LIBELLES), [...$constates, ...$signales]));

        if ($manquants === [] && ($donnees['complet'] ?? false) !== true) {
            throw new ReponseInattendueException('Réponse du connecteur MyWay incohérente (incomplète sans champ manquant).');
        }

        if ($manquants !== []) {
            Log::info('Connecteur MyWay : informations non vérifiées, aucun compte créé.', ['champs_manquants' => $manquants]);

            throw new DonneesNonVerifieesException($manquants, array_map(fn (string $champ) => self::LIBELLES[$champ], $manquants));
        }

        if (mb_strtolower((string) $profil['email']) !== $email) {
            throw new ReponseInattendueException('Le profil renvoyé par le connecteur n\'est pas celui du compte demandé.');
        }

        return new ProfilMyWay(
            cef: (string) $texte($profil['cef']),
            nom: (string) $texte($profil['nom']),
            prenom: (string) $texte($profil['prenom']),
            email: (string) $texte($profil['email']),
            cni: $texte($profil['cni'] ?? null),
            tel: $texte($profil['tel'] ?? null),
            ville: $texte($profil['ville'] ?? null),
            candidatures: $candidatures,
            provenance: array_filter((array) ($donnees['provenance'] ?? []), 'is_string'),
        );
    }
}
