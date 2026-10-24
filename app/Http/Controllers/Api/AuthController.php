<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Journal;
use App\Domain\Identity\ActivationCompte;
use App\Domain\Identity\MyWay\FournisseurIdentiteStagiaire;
use App\Domain\Identity\MyWay\FournisseurIndisponibleException;
use App\Domain\Identity\MyWay\IdentifiantsInvalidesException;
use App\Domain\Identity\RecuperationCompte;
use App\Domain\Identity\VerificationIdentiteStagiaire;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\MethodeVerification;
use App\Http\Controllers\Controller;
use App\Http\Resources\StagiaireResource;
use App\Http\Resources\UtilisateurResource;
use App\Models\NotificationInterne;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Models\VerificationIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Authentification par session (SPA Sanctum). Deux populations :
 * le personnel (comptes créés par l'administrateur) et les stagiaires.
 *
 * Un stagiaire passe une seule fois par le fournisseur d'identité (MyWay) :
 *   vérification → revue du profil → mot de passe INTERNAT → compte activé.
 * Toutes les connexions suivantes sont locales : adresse e-mail et mot de passe
 * INTERNAT, vérifiés sur l'empreinte Argon2id conservée par INTERNAT.
 */
class AuthController extends Controller
{
    private const SESSION_ETAT = 'verification_identite.etat';

    /** Informations publiques nécessaires à l'écran de connexion. */
    public function contexte(FournisseurIdentiteStagiaire $fournisseur): JsonResponse
    {
        $demo = (bool) config('internat.mode_demo') && ! app()->isProduction();

        return response()->json([
            'application' => config('app.name'),
            'mode_demo' => $demo,
            'myway' => [
                'fournisseur' => $fournisseur->nom(),
                'simule' => $fournisseur->estSimule(),
                // Ce que l'écran de première connexion doit proposer ; `indisponible` : aucun mécanisme approuvé.
                'methode' => $fournisseur->methode()->value,
                'disponible' => $fournisseur->methode() !== MethodeVerification::Indisponible,
            ],
            // Démonstration uniquement : adresses des comptes semés (jamais de mot de passe).
            'comptes_demo' => $demo ? $this->comptesDeDemonstration($fournisseur->estSimule()) : null,
        ]);
    }

    /** @return array{personnel: list<array{role: string, email: string}>, myway: list<string>} */
    private function comptesDeDemonstration(bool $mywaySimule): array
    {
        $profils = [];

        if ($mywaySimule && is_file((string) config('internat.myway.fichier_mock'))) {
            $fichier = json_decode((string) file_get_contents(config('internat.myway.fichier_mock')), true);
            $profils = array_column($fichier['profils'] ?? [], 'email');
        }

        return [
            'personnel' => Utilisateur::query()->with('roles')->where('actif', true)->where('email', 'like', '%@internat.test')
                ->orderBy('id')->limit(12)->get()
                ->map(fn (Utilisateur $u) => ['role' => (string) $u->roleCode()?->libelle(), 'email' => $u->email])->all(),
            'myway' => $profils,
        ];
    }

    public function connexionPersonnel(Request $request, Journal $journal): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ]);

        $identifiants = [
            'email' => mb_strtolower($donnees['email']),
            'password' => $donnees['mot_de_passe'],
            'actif' => true,
        ];

        // Une empreinte bcrypt héritée est recalculée en Argon2id par `attempt` (hashing.rehash_on_login).
        if (! Auth::guard('web')->attempt($identifiants)) {
            if (! Utilisateur::where('email', $identifiants['email'])->exists()) {
                $this->egaliserLeTemps($donnees['mot_de_passe']);
            }

            $journal->consigner('auth.echec', 'utilisateur', details: ['email' => $identifiants['email']]);

            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        Auth::guard('stagiaire')->logout();
        $request->session()->regenerate();

        /** @var Utilisateur $utilisateur */
        $utilisateur = Auth::guard('web')->user();
        $utilisateur->forceFill(['derniere_connexion_le' => now()])->save();
        $journal->consigner('auth.connexion', $utilisateur);

        return response()->json($this->session($utilisateur));
    }

    /**
     * Première connexion, étape 1 : vérification d'identité et d'éligibilité auprès du fournisseur.
     * Selon la méthode du fournisseur actif, la réponse est une vérification aboutie, une adresse
     * de redirection ou l'annonce d'un code envoyé. Aucun compte n'est créé ici.
     */
    public function verificationStagiaire(Request $request, VerificationIdentiteStagiaire $verification): JsonResponse
    {
        switch ($verification->methode()) {
            case MethodeVerification::Identifiants:
                $donnees = $request->validate([
                    'email' => ['required', 'email', 'max:190'],
                    'mot_de_passe_myway' => ['required', 'string', 'max:200'],
                ]);

                return $this->verificationAboutie($verification->parIdentifiants($donnees['email'], $donnees['mot_de_passe_myway']));

            case MethodeVerification::Redirection:
                // L'état lie le retour du fournisseur au navigateur qui a lancé la vérification.
                $etat = Str::random(40);
                $request->session()->put(self::SESSION_ETAT, $etat);

                return response()->json([
                    'etape' => 'redirection',
                    'url' => $verification->urlDeRedirection($etat, $this->urlDeRetour()),
                ]);

            case MethodeVerification::Code:
                $donnees = $request->validate([
                    'cef' => ['required', 'digits:13'],
                    'email' => ['required', 'email', 'max:190'],
                ]);

                return response()->json(['etape' => 'code'] + $verification->demanderCode($donnees['cef'], $donnees['email']), 202);

            default:
                // Aucun mécanisme approuvé n'est configuré : la première connexion est fermée (503), jamais simulée.
                throw new FournisseurIndisponibleException('Aucune méthode de vérification d\'identité n\'est configurée.');
        }
    }

    /** Première connexion, suite de l'étape 1 pour les méthodes en deux temps (retour de redirection, code reçu). */
    public function retourVerificationStagiaire(Request $request, VerificationIdentiteStagiaire $verification): JsonResponse
    {
        if ($verification->methode() === MethodeVerification::Code) {
            $donnees = $request->validate([
                'reference' => ['required', 'string', 'max:100'],
                'code' => ['required', 'string', 'max:20'],
            ]);

            return $this->verificationAboutie($verification->parCode($donnees['reference'], $donnees['code']));
        }

        $donnees = $request->validate([
            'etat' => ['required', 'string', 'max:100'],
            'parametres' => ['required', 'array', 'max:10'],
            'parametres.*' => ['string', 'max:2000'],
        ]);

        $attendu = (string) $request->session()->pull(self::SESSION_ETAT);

        if ($attendu === '' || ! hash_equals($attendu, $donnees['etat'])) {
            throw new IdentifiantsInvalidesException('État de redirection inconnu ou déjà utilisé.');
        }

        return $this->verificationAboutie($verification->apresRedirection($donnees['parametres'], $this->urlDeRetour()));
    }

    /**
     * Première connexion, étape 2 : le stagiaire choisit son mot de passe INTERNAT, le compte est créé et activé.
     * L'identité vient de la vérification conservée côté serveur ; seules ses coordonnées peuvent être corrigées.
     */
    public function activationStagiaire(Request $request, ActivationCompte $activation): JsonResponse
    {
        $donnees = $request->validate([
            'jeton_activation' => ['required', 'string', 'size:64'],
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
            'tel' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+ ().-]{6,30}$/'],
            'ville' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['tel.regex' => 'Numéro de téléphone invalide.']);

        $this->refuserMotDePasseDevinable($donnees['jeton_activation'], $donnees['mot_de_passe']);

        $stagiaire = $activation->activer(
            $donnees['jeton_activation'],
            $donnees['mot_de_passe'],
            array_intersect_key($donnees, array_flip(ActivationCompte::CORRECTIONS_PERMISES)),
        );

        return response()->json($this->ouvrirSessionStagiaire($request, $stagiaire), 201);
    }

    /** Connexions suivantes : adresse e-mail et mot de passe INTERNAT. MyWay n'est jamais sollicité. */
    public function connexionStagiaire(Request $request, Journal $journal): JsonResponse
    {
        $donnees = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ]);

        $email = mb_strtolower($donnees['email']);
        $stagiaire = Stagiaire::where('email', $email)->where('etat_compte', '!=', EtatCompte::EnAttente->value)->first();

        if ($stagiaire === null) {
            $this->egaliserLeTemps($donnees['mot_de_passe']);
        }

        if ($stagiaire === null || ! Hash::check($donnees['mot_de_passe'], (string) $stagiaire->mot_de_passe_hash)) {
            $journal->consigner('auth.echec', 'stagiaire', details: ['email' => $email]);

            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        // Annoncé seulement après un mot de passe correct : un tiers n'apprend rien sur l'état du compte.
        if (! $stagiaire->estActif()) {
            $journal->consigner('auth.refus_compte_suspendu', $stagiaire, acteur: $stagiaire);

            throw new RegleMetierException(
                'Votre compte Maisons des Stagiaires - OFPPT est suspendu. Contactez l\'administration de votre établissement.',
                'compte_suspendu',
                statutHttp: 403,
            );
        }

        // Empreinte héritée (bcrypt) ou paramètres Argon2id relevés depuis : recalculée à la volée.
        if (Hash::needsRehash((string) $stagiaire->mot_de_passe_hash)) {
            $stagiaire->forceFill(['mot_de_passe_hash' => $donnees['mot_de_passe']])->save();
        }

        return response()->json($this->ouvrirSessionStagiaire($request, $stagiaire));
    }

    /** Demande de lien de réinitialisation. Réponse identique que le compte existe ou non. */
    public function motDePasseOublie(Request $request, RecuperationCompte $recuperation): JsonResponse
    {
        $donnees = $request->validate([
            'espace' => ['required', Rule::in(RecuperationCompte::ESPACES)],
            'email' => ['required', 'email', 'max:190'],
        ]);

        $recuperation->demander($donnees['espace'], $donnees['email']);

        return response()->json([
            'message' => 'Si un compte Maisons des Stagiaires - OFPPT correspond à cette adresse, un lien de réinitialisation vient de lui être envoyé.',
        ]);
    }

    public function reinitialiserMotDePasse(Request $request, RecuperationCompte $recuperation): JsonResponse
    {
        $donnees = $request->validate([
            'espace' => ['required', Rule::in(RecuperationCompte::ESPACES)],
            'email' => ['required', 'email', 'max:190'],
            'jeton' => ['required', 'string', 'max:200'],
            'mot_de_passe' => ['required', 'string', 'max:200', 'confirmed', Password::defaults()],
        ]);

        $recuperation->reinitialiser($donnees['espace'], $donnees['email'], $donnees['jeton'], $donnees['mot_de_passe']);

        return response()->json(['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.']);
    }

    public function moi(Request $request): JsonResponse
    {
        return response()->json($this->session($request->user()));
    }

    public function deconnexion(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('stagiaire')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnexion effectuée.']);
    }

    /**
     * Ce que le stagiaire voit de sa vérification : le profil importé, l'origine de chaque
     * information et ce qu'il peut corriger. Le jeton n'est remis qu'ici, une seule fois.
     *
     * @param  array{verification: VerificationIdentite, jeton: string}  $resultat
     */
    private function verificationAboutie(array $resultat): JsonResponse
    {
        $verification = $resultat['verification'];
        $profil = (array) $verification->profil;

        return response()->json([
            'etape' => 'verifiee',
            'jeton_activation' => $resultat['jeton'],
            'expire_le' => $verification->expire_le,
            'expire_dans_minutes' => (int) config('internat.myway.activation_minutes'),
            'simulee' => $verification->simulee,
            'profil' => [
                'cef' => $verification->cef,
                'nom' => $profil['nom'] ?? null,
                'prenom' => $profil['prenom'] ?? null,
                'email' => $verification->email,
                'cni' => $profil['cni'] ?? null,
                'tel' => $profil['tel'] ?? null,
                'ville' => $profil['ville'] ?? null,
                'nom_filiere' => $verification->nom_filiere,
                'annee_formation' => $verification->annee_formation,
                'statut_candidature' => $verification->statut_candidature,
                'etablissement' => $verification->etablissement?->only(['code_efp', 'nom_efp']),
            ],
            'origines' => $profil['origines'] ?? [],
            'modifiables' => ActivationCompte::CORRECTIONS_PERMISES,
        ], 201);
    }

    /** Refuse un mot de passe qui reprend l'adresse ou le CEF du compte : il se devinerait à partir du dossier. */
    private function refuserMotDePasseDevinable(#[SensitiveParameter] string $jeton, #[SensitiveParameter] string $motDePasse): void
    {
        $verification = VerificationIdentite::where('jeton_hash', VerificationIdentite::empreinte($jeton))->first(['cef', 'email']);

        if ($verification === null) {
            return; // Le service d'activation répondra « vérification expirée ».
        }

        $interdits = array_filter([$verification->cef, Str::before((string) $verification->email, '@')], fn (?string $v) => mb_strlen((string) $v) >= 4);

        foreach ($interdits as $interdit) {
            if (Str::contains(mb_strtolower($motDePasse), mb_strtolower((string) $interdit))) {
                throw ValidationException::withMessages([
                    'mot_de_passe' => 'Le mot de passe ne doit contenir ni votre adresse e-mail ni votre CEF.',
                ]);
            }
        }
    }

    /**
     * Quand aucun compte ne correspond, on vérifie tout de même une empreinte : la durée de la
     * réponse ne dit pas si l'adresse existe.
     */
    private function egaliserLeTemps(#[SensitiveParameter] string $motDePasse): void
    {
        Hash::check($motDePasse, Cache::rememberForever('auth.empreinte_factice', fn () => Hash::make(Str::random(40))));
    }

    private function urlDeRetour(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/premiere-connexion/retour';
    }

    /** @return array<string, mixed> */
    private function ouvrirSessionStagiaire(Request $request, Stagiaire $stagiaire): array
    {
        Auth::guard('web')->logout();
        Auth::guard('stagiaire')->login($stagiaire);
        $request->session()->regenerate();
        $stagiaire->forceFill(['derniere_connexion_le' => now()])->save();
        app(Journal::class)->consigner('auth.connexion', $stagiaire, acteur: $stagiaire);

        return $this->session($stagiaire);
    }

    /** @return array<string, mixed> */
    private function session(Utilisateur|Stagiaire $compte): array
    {
        if ($compte instanceof Stagiaire) {
            return [
                'type' => 'stagiaire',
                'stagiaire' => new StagiaireResource($compte->load('etablissement')),
                'permissions' => [],
                'notifications_non_lues' => NotificationInterne::where('stagiaire_id', $compte->id)->where('lue', false)->count(),
            ];
        }

        return [
            'type' => 'personnel',
            'utilisateur' => new UtilisateurResource($compte->load('internat', 'etablissement', 'complexe', 'region', 'roles')),
            'permissions' => $compte->getAllPermissions()->pluck('name')->values(),
            'notifications_non_lues' => NotificationInterne::where('utilisateur_id', $compte->id)->where('lue', false)->count(),
        ];
    }
}
