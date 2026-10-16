<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Ouverture et fermeture de session, pour le personnel comme pour les stagiaires.
 *
 * Deux populations, deux gardes de session ; une seule est ouverte à la fois
 * dans un navigateur. Un échec ne dit jamais si l'adresse existe : même message,
 * et même temps de réponse, que le compte existe ou non.
 */
class Connexion
{
    private const ECHEC = 'Adresse e-mail ou mot de passe incorrect.';

    public function __construct(private readonly Journal $journal) {}

    public function personnel(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): Utilisateur
    {
        $identifiants = ['email' => mb_strtolower(trim($email)), 'password' => $motDePasse, 'actif' => true];

        // Une empreinte bcrypt héritée est recalculée en Argon2id par `attempt` (hashing.rehash_on_login).
        if (! Auth::guard('web')->attempt($identifiants)) {
            if (! Utilisateur::where('email', $identifiants['email'])->exists()) {
                $this->egaliserLeTemps($motDePasse);
            }

            $this->journal->consigner('auth.echec', 'utilisateur', details: ['email' => $identifiants['email']]);

            throw ValidationException::withMessages(['email' => self::ECHEC]);
        }

        Auth::guard('stagiaire')->logout();
        session()->regenerate();

        /** @var Utilisateur $utilisateur */
        $utilisateur = Auth::guard('web')->user();
        $utilisateur->forceFill(['derniere_connexion_le' => now()])->save();
        $this->journal->consigner('auth.connexion', $utilisateur);

        return $utilisateur;
    }

    public function stagiaire(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): Stagiaire
    {
        $email = mb_strtolower(trim($email));
        // Une ligne « en attente » héritée de l'ancien parcours n'a pas de mot de passe : elle n'ouvre aucune session.
        $stagiaire = Stagiaire::where('email', $email)->where('etat_compte', '!=', EtatCompte::EnAttente->value)->first();

        if ($stagiaire === null) {
            $this->egaliserLeTemps($motDePasse);
        }

        if ($stagiaire === null || ! Hash::check($motDePasse, (string) $stagiaire->mot_de_passe_hash)) {
            $this->journal->consigner('auth.echec', 'stagiaire', details: ['email' => $email]);

            throw ValidationException::withMessages(['email' => self::ECHEC]);
        }

        // Annoncé seulement après un mot de passe correct : un tiers n'apprend rien sur l'état du compte.
        if (! $stagiaire->estActif()) {
            $this->journal->consigner('auth.refus_compte_suspendu', $stagiaire, acteur: $stagiaire);

            throw new RegleMetierException(
                'Votre compte INTERNAT est suspendu. Contactez l\'administration de votre établissement.',
                'compte_suspendu',
                statutHttp: 403,
            );
        }

        // Empreinte héritée (bcrypt) ou paramètres Argon2id relevés depuis : recalculée à la volée.
        if (Hash::needsRehash((string) $stagiaire->mot_de_passe_hash)) {
            $stagiaire->forceFill(['mot_de_passe_hash' => $motDePasse])->save();
        }

        $this->ouvrirPourStagiaire($stagiaire);

        return $stagiaire;
    }

    /** Ouvre la session d'un stagiaire dont l'identité vient d'être établie (connexion, ou création du compte). */
    public function ouvrirPourStagiaire(Stagiaire $stagiaire): void
    {
        Auth::guard('web')->logout();
        Auth::guard('stagiaire')->login($stagiaire);
        session()->regenerate();

        $stagiaire->forceFill(['derniere_connexion_le' => now()])->save();
        $this->journal->consigner('auth.connexion', $stagiaire, acteur: $stagiaire);
    }

    public function fermer(): void
    {
        $compte = Auth::guard('web')->user() ?? Auth::guard('stagiaire')->user();

        if ($compte !== null) {
            $this->journal->consigner('auth.deconnexion', $compte, acteur: $compte);
        }

        Auth::guard('web')->logout();
        Auth::guard('stagiaire')->logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    /** Un compte inexistant coûte le même calcul d'empreinte qu'un mot de passe erroné. */
    private function egaliserLeTemps(#[SensitiveParameter] string $motDePasse): void
    {
        Hash::check($motDePasse, Cache::rememberForever('auth.empreinte_factice', fn () => Hash::make(Str::random(40))));
    }
}
