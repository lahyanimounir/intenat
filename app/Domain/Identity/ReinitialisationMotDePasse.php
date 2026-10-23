<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Enums\TypeNotification;
use App\Models\Internat;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Réinitialisation d'un mot de passe par un responsable, selon la hiérarchie :
 *
 *   Administrateur ............. tout compte du personnel (sauf le sien) et tout stagiaire
 *   Directeur de complexe ...... chefs de pôle, gestionnaires et responsables restauration de son complexe
 *   Chef de pôle ............... gestionnaires et responsables restauration de son établissement, et ses stagiaires
 *   Gestionnaire ............... stagiaires de son internat
 *   Responsable restauration ... stagiaires de son établissement
 *
 * Le nouveau mot de passe est provisoire : haché comme tout mot de passe, jamais conservé ni tracé en
 * clair, montré une seule fois au responsable pour qu'il le remette à son titulaire, qui doit le changer
 * à sa connexion suivante. L'ancien mot de passe n'est ni lisible ni récupérable. Les autres sessions
 * du compte sont fermées (intergiciel `auth.session`). Chaque réinitialisation est tracée au journal.
 */
class ReinitialisationMotDePasse
{
    /** Rôles du personnel qu'un rôle peut réinitialiser (hors administrateur, qui les peut tous). */
    private const CIBLES_PERSONNEL = [
        'DIRECTEUR' => [RoleCode::ChefPole, RoleCode::Gestionnaire, RoleCode::Restauration],
        'CHEF_POLE' => [RoleCode::Gestionnaire, RoleCode::Restauration],
    ];

    /** Rôles qui réinitialisent les stagiaires de leur périmètre. */
    private const RESPONSABLES_STAGIAIRES = [RoleCode::Admin, RoleCode::ChefPole, RoleCode::Gestionnaire, RoleCode::Restauration];

    /** Au plus 20 réinitialisations par responsable et par heure. */
    private const LIMITE_PAR_HEURE = 20;

    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
    ) {}

    /* ------------------------------------------------------------ Autorisations */

    public function peutReinitialiser(Utilisateur $responsable, Utilisateur|Stagiaire $compte): bool
    {
        return $compte instanceof Stagiaire
            ? $this->peutReinitialiserStagiaire($responsable, $compte)
            : $this->peutReinitialiserPersonnel($responsable, $compte);
    }

    public function peutReinitialiserPersonnel(Utilisateur $responsable, Utilisateur $compte): bool
    {
        if (! $responsable->actif || $responsable->is($compte) || ! $responsable->can('mots_de_passe.personnel')) {
            return false;
        }

        $role = $responsable->roleCode();

        if ($role === RoleCode::Admin) {
            return true;
        }

        $cibles = self::CIBLES_PERSONNEL[$role?->value] ?? [];

        if (! in_array($compte->roleCode(), $cibles, true)) {
            return false;
        }

        // Le compte visé doit relever entièrement des établissements dont le responsable a la charge.
        $siens = $this->etablissementsDuResponsable($responsable);
        $leurs = $this->etablissementsDuCompte($compte);

        return $leurs !== [] && array_diff($leurs, $siens) === [];
    }

    public function peutReinitialiserStagiaire(Utilisateur $responsable, Stagiaire $stagiaire): bool
    {
        return $responsable->actif
            && in_array($responsable->roleCode(), self::RESPONSABLES_STAGIAIRES, true)
            && $responsable->can('mots_de_passe.stagiaires')
            && $this->perimetre->couvreStagiaire($responsable, $stagiaire);
    }

    /**
     * Comptes du personnel que le responsable peut réinitialiser : sert à limiter la liste de l'écran.
     *
     * @param  Builder<Utilisateur>  $requete
     */
    public function restreindrePersonnel(Builder $requete, Utilisateur $responsable): Builder
    {
        $role = $responsable->roleCode();

        if ($role === RoleCode::Admin) {
            return $requete;
        }

        $cibles = array_map(fn (RoleCode $r) => $r->value, self::CIBLES_PERSONNEL[$role?->value] ?? []);
        $etablissements = $this->etablissementsDuResponsable($responsable);

        if ($cibles === [] || $etablissements === []) {
            return $requete->whereRaw('1 = 0');
        }

        return $requete->role($cibles)->where(fn (Builder $q) => $q
            ->whereIn('utilisateur.etablissement_id', $etablissements)
            ->orWhereIn('utilisateur.internat_id', Internat::whereIn('etablissement_id', $etablissements)->select('id')));
    }

    /* ------------------------------------------------------------ Réinitialisation */

    /**
     * Fixe un mot de passe provisoire (choisi par le responsable, ou généré s'il n'en donne pas) et le
     * renvoie, pour un affichage unique. Rien n'en est conservé en clair.
     *
     * @throws AuthorizationException si le compte est hors des droits ou du périmètre du responsable
     */
    public function reinitialiser(Utilisateur $responsable, Utilisateur|Stagiaire $compte, #[SensitiveParameter] ?string $motDePasse = null): string
    {
        if (! $this->peutReinitialiser($responsable, $compte)) {
            throw new AuthorizationException('Ce compte ne relève pas de votre responsabilité.');
        }

        $actif = $compte instanceof Stagiaire ? $compte->estActif() : (bool) $compte->actif;
        if (! $actif) {
            throw new RegleMetierException('Ce compte est suspendu ou désactivé : rétablissez-le d’abord.', 'compte_inactif');
        }

        $cle = 'reinitialisation-mot-de-passe:'.$responsable->id;
        if (RateLimiter::tooManyAttempts($cle, self::LIMITE_PAR_HEURE)) {
            throw new RegleMetierException('Trop de réinitialisations en peu de temps : réessayez dans '.ceil(RateLimiter::availableIn($cle) / 60).' minute(s).', 'trop_de_demandes', statutHttp: 429);
        }
        RateLimiter::hit($cle, 3600);

        $genere = blank($motDePasse);
        $provisoire = $genere ? self::generer() : (string) $motDePasse;

        DB::transaction(function () use ($responsable, $compte, $provisoire, $genere) {
            $compte->forceFill([
                'mot_de_passe_hash' => $provisoire, // haché par le modèle
                'mot_de_passe_provisoire' => true,
                'remember_token' => Str::random(60),
            ])->save();

            $this->journal->consigner('compte.mot_de_passe_reinitialise', $compte, details: [
                'compte' => $compte instanceof Stagiaire ? 'stagiaire' : ($compte->roleCode()?->value ?? 'personnel'),
                'responsable_role' => $responsable->roleCode()?->value,
                'mot_de_passe_genere' => $genere,
            ], acteur: $responsable);
        });

        $message = 'Votre mot de passe a été réinitialisé par '.$responsable->nomComplet().'. Un mot de passe provisoire vous sera remis : vous devrez le remplacer à votre prochaine connexion.';
        $compte instanceof Stagiaire
            ? $this->notifieur->versStagiaire($compte, TypeNotification::Information, 'Mot de passe réinitialisé', $message)
            : $this->notifieur->versUtilisateur($compte, TypeNotification::Information, 'Mot de passe réinitialisé', $message);

        return $provisoire;
    }

    /** Mot de passe provisoire conforme à la politique : 14 caractères, majuscules, minuscules et chiffres. */
    public static function generer(): string
    {
        $alphabet = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789'];
        $caracteres = [];
        foreach ($alphabet as $jeu) {
            $caracteres[] = $jeu[random_int(0, strlen($jeu) - 1)];
        }
        $tous = implode('', $alphabet);
        while (count($caracteres) < 14) {
            $caracteres[] = $tous[random_int(0, strlen($tous) - 1)];
        }
        shuffle($caracteres);

        return implode('', $caracteres);
    }

    /* ------------------------------------------------------------ Établissements */

    /** @return list<int> */
    private function etablissementsDuResponsable(Utilisateur $responsable): array
    {
        return match ($responsable->roleCode()) {
            RoleCode::Directeur => array_map('intval', $this->perimetre->etablissementIds($responsable) ?? []),
            RoleCode::ChefPole => $responsable->etablissement_id ? [(int) $responsable->etablissement_id] : [],
            default => [],
        };
    }

    /** @return list<int> */
    private function etablissementsDuCompte(Utilisateur $compte): array
    {
        return match ($compte->roleCode()) {
            RoleCode::ChefPole, RoleCode::Restauration => $compte->etablissement_id ? [(int) $compte->etablissement_id] : [],
            RoleCode::Gestionnaire => array_filter([(int) Internat::whereKey($compte->internat_id)->value('etablissement_id')]),
            default => [],
        };
    }
}
