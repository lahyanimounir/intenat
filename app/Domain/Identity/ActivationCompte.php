<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\OrigineChamp;
use App\Enums\ResultatVerification;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Première connexion, étape 2 : création du compte INTERNAT.
 *
 * Le stagiaire présente l'autorisation délivrée par la vérification d'identité
 * et choisit un mot de passe propre à INTERNAT. Tout se joue dans une seule
 * transaction : consommation de l'autorisation, écriture de la ligne
 * `stagiaire`, activation. Rien de partiel ne peut subsister.
 *
 * Contre le rejeu et la double activation :
 *  - l'autorisation est retrouvée par l'empreinte du jeton, jamais par un CEF,
 *    une adresse ou un numéro de CNI — les connaître ne prouve rien ;
 *  - toutes les vérifications ouvertes du même stagiaire sont verrouillées dans
 *    le même ordre : deux activations simultanées passent l'une après l'autre ;
 *  - l'identité écrite vient de la vérification conservée côté serveur, pas de
 *    la requête : le stagiaire ne peut ni s'attribuer un CEF ni se déclarer éligible ;
 *  - en dernier recours, les index uniques sur `cef` et `email` refusent un doublon.
 */
class ActivationCompte
{
    /** Seules informations que le stagiaire peut corriger avant l'activation : ses coordonnées. */
    public const CORRECTIONS_PERMISES = ['tel', 'ville'];

    public function __construct(private readonly Journal $journal) {}

    /**
     * @param  array{tel?: string|null, ville?: string|null}  $corrections
     */
    public function activer(#[SensitiveParameter] string $jeton, #[SensitiveParameter] string $motDePasse, array $corrections = []): Stagiaire
    {
        // Haché avant la transaction : Argon2id est volontairement lent, les verrous doivent rester brefs.
        $empreinteMotDePasse = Hash::make($motDePasse);
        $empreinteJeton = VerificationIdentite::empreinte($jeton);

        try {
            return DB::transaction(
                fn () => $this->activerSousVerrou($empreinteJeton, $empreinteMotDePasse, $corrections),
                attempts: 3,
            );
        } catch (UniqueConstraintViolationException) {
            throw RegleMetierException::conflit(
                'Votre compte INTERNAT est déjà activé. Connectez-vous avec votre adresse e-mail et votre mot de passe INTERNAT.',
                'compte_existant',
            );
        } catch (QueryException $e) {
            // Attente de verrou dépassée ou interblocage persistant : une autre activation du même compte est en cours.
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw RegleMetierException::conflit('Une activation de ce compte est déjà en cours. Réessayez dans un instant.', 'activation_en_cours');
            }

            throw $e;
        }
    }

    /**
     * @param  array{tel?: string|null, ville?: string|null}  $corrections
     */
    private function activerSousVerrou(string $empreinteJeton, #[SensitiveParameter] string $empreinteMotDePasse, array $corrections): Stagiaire
    {
        $visee = VerificationIdentite::where('jeton_hash', $empreinteJeton)->first(['id', 'cef']);

        if ($visee === null) {
            throw $this->autorisationInvalide();
        }

        $verrouillees = VerificationIdentite::query()
            ->where('cef', $visee->cef)
            ->where('resultat', ResultatVerification::Verifiee->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var VerificationIdentite|null $verification */
        $verification = $verrouillees->firstWhere('id', $visee->id);

        if ($verification === null || ! $verification->estUtilisable() || $verification->profil === null) {
            throw $this->autorisationInvalide();
        }

        $memesIdentifiants = Stagiaire::query()
            ->where(fn ($q) => $q->where('cef', $verification->cef)->orWhere('email', $verification->email))
            ->lockForUpdate()
            ->get();

        if ($memesIdentifiants->contains(fn (Stagiaire $s) => $s->etat_compte !== EtatCompte::EnAttente)) {
            // Le mot de passe du compte existant n'est jamais écrasé par une nouvelle activation.
            throw RegleMetierException::conflit(
                'Votre compte INTERNAT est déjà activé. Connectez-vous avec votre adresse e-mail et votre mot de passe INTERNAT.',
                'compte_existant',
            );
        }

        if ($memesIdentifiants->contains(fn (Stagiaire $s) => $s->cef !== $verification->cef || mb_strtolower($s->email) !== $verification->email)) {
            throw RegleMetierException::conflit(
                'Ce CEF ou cette adresse e-mail est déjà rattaché à un autre compte. Contactez l\'administration de votre établissement.',
                'conflit_identite',
            );
        }

        $profil = $verification->profil;
        $origines = $profil['origines'] ?? [];
        $coordonnees = [];

        foreach (self::CORRECTIONS_PERMISES as $champ) {
            $importe = $profil[$champ] ?? null;
            $saisi = array_key_exists($champ, $corrections) ? (blank($corrections[$champ]) ? null : trim((string) $corrections[$champ])) : $importe;
            $coordonnees[$champ] = $saisi;

            if ($saisi !== $importe) {
                $origines[$champ] = ($saisi === null ? OrigineChamp::Manquant : OrigineChamp::Declare)->value;
            }
        }

        // Une ligne héritée de l'ancien parcours (profil importé, jamais activé) est reprise, pas dupliquée :
        // ses dossiers et son historique restent attachés au même stagiaire.
        $stagiaire = $memesIdentifiants->first() ?? new Stagiaire;
        $maintenant = now();

        $stagiaire->forceFill([
            'etablissement_id' => $verification->etablissement_id,
            'cef' => $verification->cef,
            'nom' => $profil['nom'],
            'prenom' => $profil['prenom'],
            'email' => $verification->email,
            'cni' => $profil['cni'] ?? null,
            'tel' => $coordonnees['tel'],
            'ville' => $coordonnees['ville'],
            'nom_filiere' => $verification->nom_filiere,
            // Une identité simulée reste distinguable d'une identité lue sur MyWay.
            'source_identite' => $verification->fournisseur,
            'statut_myway' => $verification->statut_candidature,
            'annee_formation' => $verification->annee_formation,
            'origine_champs' => $origines,
            'date_import_myway' => $verification->created_at,
            'mot_de_passe_hash' => $empreinteMotDePasse,
            'mot_de_passe_modifie_le' => $maintenant,
            'compte_active_le' => $maintenant,
            'etat_compte' => EtatCompte::Actif,
        ])->save();

        // L'autorisation ne sert qu'une fois ; le profil vérifié n'a plus de raison d'être conservé à part.
        $verification->forceFill(['consommee_le' => $maintenant, 'stagiaire_id' => $stagiaire->id, 'profil' => null])->save();

        $verrouillees->where('id', '!=', $verification->id)->filter->estUtilisable()
            ->each(fn (VerificationIdentite $autre) => $autre->forceFill(['expire_le' => $maintenant, 'profil' => null])->save());

        $this->journal->consigner('stagiaire.activation', $stagiaire, details: array_filter([
            'verification_id' => $verification->id,
            'fournisseur' => $verification->fournisseur,
            'simule' => $verification->simulee,
            'statut' => $verification->statut_candidature,
            'coordonnees_corrigees' => array_keys(array_filter($origines, fn (string $o) => $o === OrigineChamp::Declare->value)) ?: null,
        ], fn ($valeur) => $valeur !== null), acteur: $stagiaire);

        return $stagiaire;
    }

    private function autorisationInvalide(): RegleMetierException
    {
        return new RegleMetierException(
            'Votre vérification a expiré ou a déjà servi. Recommencez la première connexion.',
            'jeton_expire',
        );
    }
}
