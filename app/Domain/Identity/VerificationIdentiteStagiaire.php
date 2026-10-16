<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Identity\MyWay\DonneesNonVerifieesException;
use App\Domain\Identity\MyWay\FournisseurIdentiteStagiaire;
use App\Domain\Identity\MyWay\FournisseurIndisponibleException;
use App\Domain\Identity\MyWay\ProfilMyWay;
use App\Domain\Identity\MyWay\SelectionCandidature;
use App\Domain\Identity\MyWay\VerifieParCode;
use App\Domain\Identity\MyWay\VerifieParIdentifiants;
use App\Domain\Identity\MyWay\VerifieParRedirection;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\MethodeVerification;
use App\Enums\OrigineChamp;
use App\Enums\ResultatVerification;
use App\Models\Etablissement;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Première connexion, étape 1 : vérification d'identité et d'éligibilité.
 *
 * Le fournisseur établit qui est le stagiaire ; ce service décide ensuite, sans
 * rien supposer, si cette identité ouvre droit à un compte INTERNAT :
 *
 *   compte déjà activé ............ renvoi vers la connexion habituelle
 *   aucune candidature éligible ... accès refusé, rien n'est enregistré
 *   information exigée absente .... pas d'activation, examen par l'administration
 *   plusieurs inscriptions ........ pas d'activation, examen par l'administration
 *   établissement non référencé ... pas d'activation, examen par l'administration
 *   CEF ou adresse déjà pris ...... pas d'activation, examen par l'administration
 *
 * Une vérification réussie ne crée pas de compte : elle délivre une autorisation
 * d'activation à usage unique, liée à cette identité et à cette candidature.
 * Le statut « Inscrit Payé » vérifié ici est l'inscription à la formation ; il
 * n'a aucun rapport avec le paiement de l'hébergement, suivi dans `paiement`.
 */
class VerificationIdentiteStagiaire
{
    /** Informations sans lesquelles aucun compte n'est activé. */
    private const LIBELLES_EXIGES = ['cef' => 'CEF', 'nom' => 'nom', 'prenom' => 'prénom'];

    public function __construct(
        private readonly FournisseurIdentiteStagiaire $fournisseur,
        private readonly Journal $journal,
    ) {}

    public function methode(): MethodeVerification
    {
        return $this->fournisseur->methode();
    }

    /**
     * @return array{verification: VerificationIdentite, jeton: string}
     */
    public function parIdentifiants(#[SensitiveParameter] string $email, #[SensitiveParameter] string $motDePasse): array
    {
        $fournisseur = $this->exiger(VerifieParIdentifiants::class);
        $email = mb_strtolower(trim($email));

        try {
            // Peut lever IdentifiantsInvalides / FournisseurIndisponible / ReponseInattendue.
            $profil = $fournisseur->authentifier($email, $motDePasse);
        } catch (DonneesNonVerifieesException $e) {
            // Le titulaire s'est authentifié, mais une information exigée n'a pas pu être lue : à examiner.
            $this->garderPourExamen(ResultatVerification::InformationsIncompletes, $email, champsManquants: $e->contexte['champs_manquants'] ?? []);

            throw $e;
        }

        return $this->etablir($profil);
    }

    public function urlDeRedirection(string $etat, string $urlRetour): string
    {
        return $this->exiger(VerifieParRedirection::class)->urlAutorisation($etat, $urlRetour);
    }

    /**
     * @param  array<string, string>  $parametres
     * @return array{verification: VerificationIdentite, jeton: string}
     */
    public function apresRedirection(array $parametres, string $urlRetour): array
    {
        return $this->etablir($this->exiger(VerifieParRedirection::class)->terminer($parametres, $urlRetour));
    }

    /** @return array{reference: string, canal: string, expire_dans_minutes: int} */
    public function demanderCode(string $cef, string $email): array
    {
        return $this->exiger(VerifieParCode::class)->demanderCode(trim($cef), mb_strtolower(trim($email)));
    }

    /**
     * @return array{verification: VerificationIdentite, jeton: string}
     */
    public function parCode(string $reference, #[SensitiveParameter] string $code): array
    {
        return $this->etablir($this->exiger(VerifieParCode::class)->confirmerCode($reference, $code));
    }

    /**
     * Décide de la suite à donner à une identité que le fournisseur vient d'établir.
     *
     * @return array{verification: VerificationIdentite, jeton: string}
     */
    private function etablir(ProfilMyWay $profil): array
    {
        $email = mb_strtolower(trim($profil->email));
        $cef = $profil->cef !== null && preg_match('/^\d{13}$/', $profil->cef) ? $profil->cef : null;

        // La personne vient de prouver son identité : lui dire que son compte existe ne révèle rien à un tiers.
        if ($this->compteExistant($cef, $email)) {
            throw RegleMetierException::conflit(
                'Votre compte INTERNAT est déjà activé. Connectez-vous avec votre adresse e-mail et votre mot de passe INTERNAT.',
                'compte_existant',
            );
        }

        $selection = SelectionCandidature::retenir($profil, (string) config('internat.myway.statut_eligible'));

        if ($selection['motif'] === SelectionCandidature::AUCUNE) {
            // Rien n'est enregistré sur un stagiaire non éligible : seule la trace anonyme du refus subsiste.
            $this->journal->consigner('stagiaire.verification_refusee', 'verification_identite', details: [
                'motif' => 'non_eligible', 'fournisseur' => $this->fournisseur->nom(),
            ]);

            throw new RegleMetierException(
                'Accès refusé : aucune de vos candidatures MyWay n\'est au statut « Inscrit Payé ».',
                'non_eligible',
                statutHttp: 403,
            );
        }

        $manquants = array_keys(array_filter([
            'cef' => $cef === null,
            'nom' => blank($profil->nom),
            'prenom' => blank($profil->prenom),
        ]));

        if ($manquants !== []) {
            $this->garderPourExamen(ResultatVerification::InformationsIncompletes, $email, $cef, champsManquants: $manquants);

            throw new RegleMetierException(
                'Ces informations n\'ont pas pu être vérifiées auprès de MyWay : '
                    .implode(', ', array_map(fn (string $champ) => self::LIBELLES_EXIGES[$champ], $manquants))
                    .'. Votre compte ne peut pas être activé automatiquement : l\'administration de votre établissement en est informée.',
                'informations_incompletes',
                ['champs_manquants' => $manquants],
            );
        }

        if ($selection['motif'] === SelectionCandidature::AMBIGUE) {
            $this->garderPourExamen(ResultatVerification::CandidatureAmbigue, $email, $cef);

            throw RegleMetierException::conflit(
                'Plusieurs de vos inscriptions sont au statut « Inscrit Payé » : INTERNAT ne peut pas choisir à votre place. '
                    .'L\'administration de votre établissement en est informée.',
                'candidature_ambigue',
            );
        }

        $candidature = $selection['candidature'];
        $etablissement = $this->trouverEtablissement($candidature['nom_efp']);

        if ($etablissement === null) {
            $this->garderPourExamen(ResultatVerification::EfpInconnu, $email, $cef, $candidature['nom_efp']);

            throw new RegleMetierException(
                'Votre établissement n\'est pas encore référencé dans INTERNAT. L\'administration en est informée : réessayez plus tard.',
                'efp_inconnu',
            );
        }

        if ($this->identiteEnConflit($cef, $email)) {
            $this->garderPourExamen(ResultatVerification::ConflitIdentite, $email, $cef);

            throw RegleMetierException::conflit(
                'Ce CEF ou cette adresse e-mail est déjà rattaché à un autre compte. Contactez l\'administration de votre établissement.',
                'conflit_identite',
            );
        }

        $jeton = Str::random(64);

        $verification = DB::transaction(function () use ($profil, $cef, $email, $candidature, $etablissement, $jeton) {
            // Une seule autorisation valable par stagiaire : toute vérification antérieure encore ouverte est fermée.
            VerificationIdentite::enCours()
                ->where(fn ($q) => $q->where('cef', $cef)->orWhere('email', $email))
                ->update(['expire_le' => now(), 'profil' => null]);

            $verification = VerificationIdentite::create([
                'fournisseur' => $this->fournisseur->nom(),
                'methode' => $this->fournisseur->methode(),
                'simulee' => $this->fournisseur->estSimule(),
                'resultat' => ResultatVerification::Verifiee,
                'cef' => $cef,
                'email' => $email,
                'profil' => [
                    'nom' => $profil->nom,
                    'prenom' => $profil->prenom,
                    'cni' => $profil->cni,
                    'tel' => $profil->tel,
                    'ville' => $profil->ville,
                    'origines' => $this->origines($profil),
                    'provenance' => $profil->provenance,
                ],
                'etablissement_id' => $etablissement->id,
                'nom_efp' => $candidature['nom_efp'],
                'nom_filiere' => $candidature['nom_filiere'],
                'statut_candidature' => $candidature['statut'],
                'annee_formation' => $candidature['annee'] ?? null,
                'jeton_hash' => VerificationIdentite::empreinte($jeton),
                'expire_le' => now()->addMinutes((int) config('internat.myway.activation_minutes')),
                'adresse_ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            $this->journal->consigner('stagiaire.verification_identite', $verification, details: array_filter([
                'fournisseur' => $this->fournisseur->nom(),
                'methode' => $this->fournisseur->methode()->value,
                'simule' => $this->fournisseur->estSimule(),
                'code_efp' => $etablissement->code_efp,
                'statut' => $candidature['statut'],
                // Lecture réelle : d'où vient chaque champ (réponse et chemin), sans aucune valeur.
                'provenance' => $profil->provenance ?: null,
            ], fn ($valeur) => $valeur !== null));

            return $verification;
        });

        return ['verification' => $verification->setRelation('etablissement', $etablissement), 'jeton' => $jeton];
    }

    /**
     * @template T of FournisseurIdentiteStagiaire
     *
     * @param  class-string<T>  $contrat
     * @return T
     */
    private function exiger(string $contrat): FournisseurIdentiteStagiaire
    {
        if (! $this->fournisseur instanceof $contrat) {
            // Méthode non proposée par le fournisseur actif (ou aucune : intégration officielle en attente).
            throw new FournisseurIndisponibleException('Méthode de vérification indisponible avec le fournisseur « '.$this->fournisseur->nom().' ».');
        }

        return $this->fournisseur;
    }

    /**
     * Un compte activé existe pour cette même identité (même adresse, et même CEF quand il a été lu).
     * Une identité qui ne recoupe qu'à moitié un compte existant est un conflit, traité plus bas.
     */
    private function compteExistant(?string $cef, string $email): bool
    {
        return Stagiaire::query()
            ->where('etat_compte', '!=', EtatCompte::EnAttente->value)
            ->where('email', $email)
            ->when($cef !== null, fn ($q) => $q->where('cef', $cef))
            ->exists();
    }

    /** Le CEF est porté par une autre adresse, ou l'adresse par un autre CEF. */
    private function identiteEnConflit(string $cef, string $email): bool
    {
        return Stagiaire::query()
            ->where(fn ($q) => $q->where('cef', $cef)->orWhere('email', $email))
            ->get(['cef', 'email'])
            ->contains(fn (Stagiaire $s) => $s->cef !== $cef || mb_strtolower($s->email) !== $email);
    }

    /** Le Code EFP n'est pas fourni par MyWay : il vient de la correspondance tenue par l'administrateur. */
    private function trouverEtablissement(string $nomEfp): ?Etablissement
    {
        $attendu = ProfilMyWay::normaliser($nomEfp);

        return Etablissement::query()->get(['id', 'code_efp', 'nom_efp'])
            ->first(fn (Etablissement $e) => ProfilMyWay::normaliser($e->nom_efp) === $attendu);
    }

    /** @return array<string, string> */
    private function origines(ProfilMyWay $profil): array
    {
        $lu = fn (?string $valeur) => (blank($valeur) ? OrigineChamp::Manquant : OrigineChamp::Importe)->value;

        return [
            'cef' => OrigineChamp::Importe->value,
            'nom' => OrigineChamp::Importe->value,
            'prenom' => OrigineChamp::Importe->value,
            'email' => OrigineChamp::Importe->value,
            'nom_filiere' => OrigineChamp::Importe->value,
            'nom_efp' => OrigineChamp::Importe->value,
            'code_efp' => OrigineChamp::Correspondance->value,
            'cni' => $lu($profil->cni),
            'tel' => $lu($profil->tel),
            'ville' => $lu($profil->ville),
        ];
    }

    /**
     * Garde une vérification non aboutie pour examen : adresse authentifiée, CEF s'il a été lu,
     * et ce qui a bloqué. Jamais le profil. Une tentative répétée met à jour la ligne ouverte.
     *
     * @param  list<string>  $champsManquants
     */
    private function garderPourExamen(
        ResultatVerification $resultat,
        string $email,
        ?string $cef = null,
        ?string $nomEfp = null,
        array $champsManquants = [],
    ): void {
        $ouverte = VerificationIdentite::query()
            ->where('email', $email)->where('resultat', $resultat->value)->whereNull('examinee_le')
            ->first() ?? new VerificationIdentite;

        $ouverte->fill([
            'fournisseur' => $this->fournisseur->nom(),
            'methode' => $this->fournisseur->methode(),
            'simulee' => $this->fournisseur->estSimule(),
            'resultat' => $resultat,
            'email' => $email,
            'cef' => $cef,
            'nom_efp' => $nomEfp,
            'champs_manquants' => $champsManquants ?: null,
            'adresse_ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
        $ouverte->updated_at = now();
        $ouverte->save();

        $this->journal->consigner('stagiaire.verification_a_examiner', $ouverte, details: array_filter([
            'resultat' => $resultat->value,
            'fournisseur' => $this->fournisseur->nom(),
            'champs_manquants' => $champsManquants ?: null,
        ]));
    }
}
