<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Récupération d'un compte INTERNAT par lien envoyé à l'adresse du compte.
 *
 * Vaut pour le personnel comme pour les stagiaires, avec deux jeux de jetons
 * distincts. Un stagiaire qui a oublié son mot de passe INTERNAT ne repasse
 * pas par MyWay : la « première connexion » n'écrase jamais un compte existant.
 *
 *  - la demande reçoit toujours la même réponse, que l'adresse corresponde ou
 *    non à un compte utilisable : aucune énumération ;
 *  - le jeton est conservé haché, expire, ne sert qu'une fois, et les demandes
 *    répétées sont espacées (config/auth.php) ;
 *  - changer le mot de passe ferme les autres sessions ouvertes du compte
 *    (intergiciel `auth.session` des routes).
 */
class RecuperationCompte
{
    public const ESPACES = ['personnel', 'stagiaire'];

    public function __construct(private readonly Journal $journal) {}

    /** Envoie le lien si l'adresse désigne un compte utilisable ; ne dit jamais si c'est le cas. */
    public function demander(string $espace, #[SensitiveParameter] string $email): void
    {
        $courtier = $this->courtier($espace);
        $compte = $courtier->getUser(['email' => mb_strtolower(trim($email))]);

        if (! $this->utilisable($compte)) {
            return;
        }

        if ($courtier->sendResetLink(['email' => $compte->email]) === Password::RESET_LINK_SENT) {
            $this->journal->consigner('auth.reinitialisation_demandee', $compte, acteur: $compte);
        }
    }

    public function reinitialiser(string $espace, #[SensitiveParameter] string $email, #[SensitiveParameter] string $jeton, #[SensitiveParameter] string $motDePasse): void
    {
        $statut = $this->courtier($espace)->reset(
            ['email' => mb_strtolower(trim($email)), 'token' => $jeton, 'password' => $motDePasse],
            function (Utilisateur|Stagiaire $compte, #[SensitiveParameter] string $motDePasse) {
                if (! $this->utilisable($compte)) {
                    throw $this->lienInvalide();
                }

                $compte->forceFill(['mot_de_passe_hash' => $motDePasse, 'mot_de_passe_provisoire' => false, 'remember_token' => Str::random(60)]);

                if ($compte instanceof Stagiaire) {
                    $compte->mot_de_passe_modifie_le = now();
                }

                $compte->save();
                $this->journal->consigner('auth.mot_de_passe_reinitialise', $compte, acteur: $compte);
            },
        );

        if ($statut !== Password::PASSWORD_RESET) {
            throw $this->lienInvalide();
        }
    }

    private function courtier(string $espace): PasswordBroker
    {
        return Password::broker($espace === 'stagiaire' ? 'stagiaires' : 'utilisateurs');
    }

    /** Un compte désactivé, suspendu ou jamais activé ne se récupère pas par courriel. */
    private function utilisable(mixed $compte): bool
    {
        return match (true) {
            $compte instanceof Utilisateur => $compte->actif,
            $compte instanceof Stagiaire => $compte->estActif(),
            default => false,
        };
    }

    /** Même message quelle que soit la cause (lien périmé, déjà utilisé, adresse différente, compte fermé). */
    private function lienInvalide(): RegleMetierException
    {
        return new RegleMetierException(
            'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.',
            'lien_invalide',
        );
    }
}
