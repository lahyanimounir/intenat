<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\StatutInscription;
use App\Models\Stagiaire;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Inscription d'un stagiaire sur INTERNAT, sans aucun service tiers.
 *
 *   1. création du compte : adresse e-mail et mot de passe ;
 *   2. déclaration de l'identité : CEF, nom, prénom, CNI, téléphone, ville, adresse, genre ;
 *   3. déclaration de la formation : établissement du référentiel, filière, niveau, année.
 *
 * Tout ce qui est saisi ici est une déclaration. Elle n'ouvre aucun droit : la
 * vérification de l'inscription en formation est une étape distincte, tenue par
 * un agent habilité (voir ControleInscription).
 */
class InscriptionStagiaire
{
    /** Coordonnées : toujours modifiables par le stagiaire, elles ne sont pas l'objet de la vérification. */
    private const COORDONNEES = ['tel', 'ville', 'adresse'];

    private const IDENTITE = ['cef', 'nom', 'prenom', 'cni', 'genre'];

    private const FORMATION = ['etablissement_id', 'nom_filiere', 'niveau_formation', 'annee_formation', 'annee_scolaire'];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Crée le compte. L'adresse n'est pas une preuve d'inscription en formation, même une fois confirmée.
     *
     * `$adresseConfirmee` : marque l'adresse comme vérifiée sans envoyer de lien. C'est le cas du
     * parcours d'inscription complet (compte créé au moment de la soumission), où aucune confirmation
     * d'e-mail n'est exigée — seuls le format et l'unicité sont contrôlés.
     */
    public function creerCompte(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse, bool $adresseConfirmee = false): Stagiaire
    {
        $email = mb_strtolower(trim($email));

        try {
            $stagiaire = DB::transaction(function () use ($email, $motDePasse, $adresseConfirmee) {
                $stagiaire = Stagiaire::create([
                    'email' => $email,
                    'mot_de_passe_hash' => $motDePasse,
                    'etat_compte' => EtatCompte::Actif,
                    'compte_active_le' => now(),
                    'inscrit_le' => now(),
                    'mot_de_passe_modifie_le' => now(),
                    'email_verifie_le' => $adresseConfirmee ? now() : null,
                    'statut_inscription' => StatutInscription::NonDeclaree,
                    'source_identite' => 'declaration',
                ]);

                $this->journal->consigner('stagiaire.inscription', $stagiaire, acteur: $stagiaire);

                return $stagiaire;
            });
        } catch (UniqueConstraintViolationException) {
            throw $adresseConfirmee ? $this->adresseDejaUtilisee() : $this->adresseIndisponible();
        } catch (QueryException $erreur) {
            throw $this->operationEnCours($erreur);
        }

        if (! $adresseConfirmee && Stagiaire::verificationAdresseExigee()) {
            $stagiaire->envoyerVerificationAdresse();
        }

        return $stagiaire;
    }

    /** Même message qu'une adresse soit déjà prise ou non utilisable : il ne décrit pas le compte existant. */
    public function adresseIndisponible(): RegleMetierException
    {
        return new RegleMetierException(
            'Cette adresse ne peut pas être utilisée pour créer un compte. Si vous en avez déjà un, connectez-vous ou réinitialisez votre mot de passe.',
            'adresse_indisponible',
        );
    }

    /** Message explicite demandé pour le parcours d'inscription : l'adresse est déjà rattachée à un compte. */
    public function adresseDejaUtilisee(): RegleMetierException
    {
        return new RegleMetierException('Cette adresse e-mail est déjà utilisée.', 'adresse_deja_utilisee');
    }

    /** Le lien signé a déjà été contrôlé par la route : il ne reste qu'à dater la confirmation. */
    public function confirmerAdresse(Stagiaire $stagiaire): void
    {
        if ($stagiaire->adresseVerifiee()) {
            return;
        }

        $stagiaire->forceFill(['email_verifie_le' => now()])->save();
        $this->journal->consigner('stagiaire.adresse_confirmee', $stagiaire, acteur: $stagiaire);
    }

    /**
     * Identité et coordonnées. Une fois l'inscription soumise ou vérifiée, seules les coordonnées restent modifiables.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function declarerIdentite(Stagiaire $stagiaire, array $donnees): Stagiaire
    {
        $champs = self::COORDONNEES;

        if ($stagiaire->statut_inscription->modifiable()) {
            $champs = [...$champs, ...self::IDENTITE];

            // Le genre désigne l'internat : il ne change plus dès qu'un dossier a été déposé.
            if ($stagiaire->genreVerrouille()) {
                $champs = array_diff($champs, ['genre']);
            }
        }

        return $this->enregistrer($stagiaire, collect($donnees)->only($champs)->all(), 'stagiaire.identite_declaree');
    }

    /**
     * Formation déclarée. L'établissement vient du référentiel tenu par l'administration, jamais d'une saisie libre.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function declarerFormation(Stagiaire $stagiaire, array $donnees): Stagiaire
    {
        if (! $stagiaire->statut_inscription->modifiable()) {
            throw new RegleMetierException(
                $stagiaire->inscriptionVerifiee()
                    ? 'Votre inscription a été vérifiée : la formation déclarée ne peut plus être modifiée. Adressez-vous à votre établissement en cas d\'erreur.'
                    : 'Votre inscription est en cours de vérification. Retirez votre demande pour modifier la formation déclarée.',
                'inscription_verrouillee',
            );
        }

        if (! $stagiaire->identiteDeclaree()) {
            throw new RegleMetierException('Renseignez d\'abord votre identité.', 'identite_manquante');
        }

        return $this->enregistrer($stagiaire, collect($donnees)->only(self::FORMATION)->all(), 'stagiaire.formation_declaree');
    }

    /** @param  array<string, mixed>  $valeurs */
    private function enregistrer(Stagiaire $stagiaire, array $valeurs, string $action): Stagiaire
    {
        try {
            return DB::transaction(function () use ($stagiaire, $valeurs, $action) {
                $stagiaire = Stagiaire::lockForUpdate()->findOrFail($stagiaire->id);
                $stagiaire->fill($valeurs);

                $modifies = array_keys($stagiaire->getDirty());

                if ($modifies === []) {
                    return $stagiaire;
                }

                $stagiaire->marquerDeclare($modifies);

                // Le profil devient « déclaré » dès qu'il est complet ; il ne repasse jamais « non déclaré » ensuite.
                if ($stagiaire->statut_inscription === StatutInscription::NonDeclaree && $stagiaire->profilComplet()) {
                    $stagiaire->statut_inscription = StatutInscription::Declaree;
                    $stagiaire->profil_complete_le = now();
                }

                $stagiaire->save();

                // Le journal dit quels champs ont changé, jamais leur valeur : ce sont des données personnelles.
                $this->journal->consigner($action, $stagiaire, details: ['champs' => $modifies], acteur: $stagiaire);

                return $stagiaire;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RegleMetierException(
                'Ce CEF est déjà rattaché à un autre compte. Vérifiez votre saisie ; si le numéro est bien le vôtre, contactez l\'administration de votre établissement.',
                'cef_deja_utilise',
            );
        } catch (QueryException $erreur) {
            throw $this->operationEnCours($erreur);
        }
    }

    /**
     * Une autre session écrit au même moment la même adresse ou le même CEF : la base fait attendre la seconde,
     * qui renonce à l'expiration du verrou. On le dit à la personne plutôt que de laisser remonter une erreur interne.
     */
    private function operationEnCours(QueryException $erreur): Throwable
    {
        // 1205 : attente de verrou dépassée ; 1213 : interblocage résolu par MySQL.
        if (! in_array($erreur->errorInfo[1] ?? null, [1205, 1213], true)) {
            return $erreur;
        }

        return RegleMetierException::conflit('Une autre opération est en cours sur ce compte. Réessayez dans un instant.', 'operation_en_cours');
    }
}
