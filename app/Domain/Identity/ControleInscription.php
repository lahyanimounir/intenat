<?php

namespace App\Domain\Identity;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\OrigineVerificationInscription;
use App\Enums\RoleCode;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Enums\StatutVerificationInscription;
use App\Enums\TypeNotification;
use App\Jobs\RecalculerClassement;
use App\Models\Demande;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Models\VerificationInscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vérification administrative de l'inscription en formation.
 *
 * Elle remplace le contrôle du statut « Inscrit Payé » autrefois lu sur MyWay :
 * le stagiaire soumet ce qu'il a déclaré, avec un justificatif ; un agent
 * habilité, dans son périmètre, vérifie, demande une correction ou rejette.
 * Cocher une case ou déposer un fichier ne rend jamais éligible : seule la
 * décision d'un agent le fait, et chaque décision est conservée et journalisée.
 *
 * Proposition à faire valider par l'OFPPT avant toute campagne réelle
 * (docs/decisions/0008-inscription-manuelle-et-verification-administrative.md).
 *
 * Ne pas confondre avec le paiement de l'hébergement (SuiviPaiements), qui
 * intervient après l'admission : vérifier une inscription ne règle aucun mois
 * d'internat, et inversement.
 */
class ControleInscription
{
    public const PERMISSION = 'inscriptions.verifier';

    private const FORMATS = 'PDF, JPG, PNG';

    public function __construct(
        private readonly Documents $documents,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
        private readonly Perimetre $perimetre,
        private readonly Parametres $parametres,
        private readonly TransitionDemande $transition,
    ) {}

    public function justificatifObligatoire(): bool
    {
        return $this->parametres->vrai('inscription.justificatif_obligatoire');
    }

    /** Le stagiaire soumet sa déclaration à la vérification, avec son justificatif d'inscription. */
    public function soumettre(Stagiaire $stagiaire, ?UploadedFile $justificatif): VerificationInscription
    {
        if (! $stagiaire->profilComplet()) {
            throw new RegleMetierException('Complétez votre identité et votre formation avant de soumettre votre inscription.', 'profil_incomplet');
        }

        if (! $stagiaire->statut_inscription->soumettable()) {
            throw new RegleMetierException(
                $stagiaire->inscriptionVerifiee() ? 'Votre inscription est déjà vérifiée.' : 'Votre inscription est déjà en cours de vérification.',
                'inscription_deja_soumise',
            );
        }

        if ($stagiaire->doitVerifierSonAdresse()) {
            throw new RegleMetierException('Confirmez d\'abord votre adresse e-mail : un lien vous a été envoyé.', 'adresse_non_verifiee');
        }

        if ($justificatif !== null) {
            $regles = $this->documents->regles(self::FORMATS);
            $stocke = $this->documents->enregistrer($justificatif, "inscriptions/{$stagiaire->id}", $regles['extensions'], $regles['taille_max_ko']);
        } else {
            // Nouvelle soumission sans nouveau fichier (candidature retirée puis modifiée) : on reprend le précédent.
            $stocke = $this->reprendreDernierJustificatif($stagiaire);

            if ($stocke === null && $this->justificatifObligatoire()) {
                throw new RegleMetierException(
                    'Joignez un justificatif d\'inscription : attestation d\'inscription ou reçu des frais de scolarité.',
                    'justificatif_manquant',
                );
            }
        }

        try {
            $verification = DB::transaction(function () use ($stagiaire, $stocke) {
                $stagiaire = Stagiaire::lockForUpdate()->findOrFail($stagiaire->id);

                if (! $stagiaire->statut_inscription->soumettable()) {
                    throw new RegleMetierException('Votre inscription a déjà été soumise.', 'inscription_deja_soumise');
                }

                $verification = VerificationInscription::create([
                    'stagiaire_id' => $stagiaire->id,
                    'origine' => OrigineVerificationInscription::Declaration,
                    'statut' => StatutVerificationInscription::Soumise,
                    'soumise_le' => now(),
                    'fichier' => $stocke['chemin'] ?? null,
                    'nom_original' => $stocke['nom_original'] ?? null,
                    'mime' => $stocke['mime'] ?? null,
                    'taille' => $stocke['taille'] ?? null,
                ] + $this->declaration($stagiaire));

                $stagiaire->forceFill([
                    'statut_inscription' => StatutInscription::Soumise,
                    'inscription_verifiee_le' => null,
                ])->save();

                $this->journal->consigner('inscription.soumise', $verification, details: ['justificatif' => $stocke !== null], acteur: $stagiaire);

                return $verification;
            });
        } catch (\Throwable $erreur) {
            // La soumission n'a pas abouti : le fichier déjà écrit ne reste pas orphelin.
            $this->documents->supprimer($stocke['chemin'] ?? null);

            throw $erreur;
        }

        $this->prevenirLesAgents($stagiaire, $verification);

        return $verification;
    }

    /**
     * Reprend le justificatif de la dernière soumission (candidature retirée puis
     * re-soumise sans nouveau fichier) : le fichier est copié pour rester autonome.
     *
     * @return array{chemin: string, nom_original: string|null, mime: string|null, taille: int|null}|null
     */
    private function reprendreDernierJustificatif(Stagiaire $stagiaire): ?array
    {
        $precedente = VerificationInscription::where('stagiaire_id', $stagiaire->id)
            ->whereNotNull('fichier')->latest('id')->first();

        if ($precedente === null) {
            return null;
        }

        $disque = $this->documents->disque();

        if (! $disque->exists($precedente->fichier)) {
            return null;
        }

        $extension = pathinfo((string) $precedente->fichier, PATHINFO_EXTENSION);
        $chemin = "inscriptions/{$stagiaire->id}/".Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $disque->copy($precedente->fichier, $chemin);

        return [
            'chemin' => $chemin,
            'nom_original' => $precedente->nom_original,
            'mime' => $precedente->mime,
            'taille' => $precedente->taille,
        ];
    }

    /** Le stagiaire retire sa demande en attente pour corriger ce qu'il a déclaré. */
    public function retirer(Stagiaire $stagiaire): void
    {
        DB::transaction(function () use ($stagiaire) {
            $stagiaire = Stagiaire::lockForUpdate()->findOrFail($stagiaire->id);
            $enAttente = VerificationInscription::where('stagiaire_id', $stagiaire->id)->enAttente()->lockForUpdate()->get();

            if ($stagiaire->statut_inscription !== StatutInscription::Soumise || $enAttente->isEmpty()) {
                throw new RegleMetierException('Aucune demande de vérification n\'est en attente.', 'aucune_demande');
            }

            foreach ($enAttente as $verification) {
                $verification->forceFill(['statut' => StatutVerificationInscription::Remplacee, 'decidee_le' => now()])->save();
                $this->journal->consigner('inscription.retiree', $verification, acteur: $stagiaire);
            }

            $stagiaire->forceFill(['statut_inscription' => StatutInscription::Declaree])->save();
        });
    }

    /**
     * Remet une inscription vérifiée « en attente de validation » : une nouvelle vérification
     * est ouverte et le séjour se referme. Sert quand le chef de pôle ou le directeur annule la
     * validation d'une candidature : le gestionnaire devra l'examiner de nouveau. L'autorisation
     * est contrôlée par l'appelant (permission `admissions.annuler_validation`).
     */
    public function remettreEnAttente(Stagiaire $stagiaire, string $motif, Utilisateur $agent): VerificationInscription
    {
        return DB::transaction(function () use ($stagiaire, $motif, $agent) {
            $stagiaire = Stagiaire::lockForUpdate()->findOrFail($stagiaire->id);

            if (! $stagiaire->inscriptionVerifiee()) {
                throw new RegleMetierException('Seule une inscription vérifiée peut être remise en attente de validation.', 'non_verifiee');
            }

            $verification = VerificationInscription::create([
                'stagiaire_id' => $stagiaire->id,
                'origine' => OrigineVerificationInscription::Administration,
                'statut' => StatutVerificationInscription::Soumise,
                'soumise_le' => now(),
                'motif' => mb_substr(trim($motif), 0, 500) ?: null,
            ] + $this->declaration($stagiaire));

            $stagiaire->forceFill(['statut_inscription' => StatutInscription::Soumise, 'inscription_verifiee_le' => null])->save();

            $this->journal->consigner('inscription.remise_en_attente', $verification, details: ['motif' => $motif], acteur: $agent);

            return $verification;
        });
    }

    /** Décision d'un agent sur une demande soumise : vérifiée, à corriger ou rejetée. */
    public function decider(VerificationInscription $verification, StatutVerificationInscription $decision, ?string $motif, Utilisateur $agent): VerificationInscription
    {
        $motif = $this->motifValide($decision, $motif, obligatoire: $decision !== StatutVerificationInscription::Verifiee);

        $verification = DB::transaction(function () use ($verification, $decision, $motif, $agent) {
            $verification = VerificationInscription::lockForUpdate()->findOrFail($verification->id);
            $stagiaire = Stagiaire::lockForUpdate()->findOrFail($verification->stagiaire_id);
            $this->exigerAcces($agent, $stagiaire);

            if ($verification->statut !== StatutVerificationInscription::Soumise) {
                throw RegleMetierException::conflit('Cette demande a déjà été traitée ou retirée.', 'verification_deja_traitee');
            }

            $verification->forceFill([
                'statut' => $decision,
                'decidee_le' => now(),
                'decidee_par' => $agent->id,
                'motif' => $motif,
            ])->save();

            $this->reporter($stagiaire, $decision);
            $this->journal->consigner('inscription.'.$decision->value, $verification, details: array_filter(['motif' => $motif]), acteur: $agent);

            return $verification;
        });

        $this->prevenirLeStagiaire($verification);

        return $verification;
    }

    /**
     * Décision prise sans demande en attente : vérification sur le registre de scolarité d'une
     * inscription seulement déclarée, confirmation d'une reprise MyWay, ou remise en cause d'une
     * inscription déjà vérifiée. Le motif, toujours exigé, dit sur quoi l'agent s'est fondé.
     */
    public function deciderSansDemande(Stagiaire $stagiaire, StatutVerificationInscription $decision, ?string $motif, Utilisateur $agent): VerificationInscription
    {
        $motif = $this->motifValide($decision, $motif, obligatoire: true);

        $verification = DB::transaction(function () use ($stagiaire, $decision, $motif, $agent) {
            $stagiaire = Stagiaire::lockForUpdate()->findOrFail($stagiaire->id);
            $this->exigerAcces($agent, $stagiaire);

            if (! in_array($stagiaire->statut_inscription, [StatutInscription::Declaree, StatutInscription::Verifiee, StatutInscription::ACorriger, StatutInscription::Rejetee], true)) {
                throw new RegleMetierException(
                    $stagiaire->statut_inscription === StatutInscription::Soumise
                        ? 'Une demande est en attente : traitez-la depuis la liste des inscriptions à vérifier.'
                        : 'Ce stagiaire n\'a pas encore déclaré sa formation.',
                    'decision_impossible',
                );
            }

            if ($stagiaire->inscriptionVerifiee() && $decision !== StatutVerificationInscription::Verifiee) {
                $this->rouvrirLesDossiers($stagiaire);
            }

            $verification = VerificationInscription::create([
                'stagiaire_id' => $stagiaire->id,
                'origine' => OrigineVerificationInscription::Administration,
                'statut' => $decision,
                'soumise_le' => now(),
                'decidee_le' => now(),
                'decidee_par' => $agent->id,
                'motif' => $motif,
            ] + $this->declaration($stagiaire));

            $this->reporter($stagiaire, $decision);
            $this->journal->consigner('inscription.'.$decision->value, $verification, details: ['motif' => $motif, 'sans_demande' => true], acteur: $agent);

            return $verification;
        });

        $this->prevenirLeStagiaire($verification);

        return $verification;
    }

    /** L'agent détient la permission et le stagiaire relève de son périmètre. */
    public function couvre(Utilisateur $agent, Stagiaire $stagiaire): bool
    {
        return $agent->actif && $agent->can(self::PERMISSION) && $this->perimetre->couvreStagiaire($agent, $stagiaire);
    }

    /** @throws AuthorizationException */
    public function exigerAcces(Utilisateur $agent, Stagiaire $stagiaire): void
    {
        if (! $this->couvre($agent, $stagiaire)) {
            throw new AuthorizationException('Ce stagiaire ne relève pas de votre périmètre.');
        }
    }

    /**
     * Ce qui est soumis à la vérification, figé au moment de la soumission.
     *
     * @return array<string, mixed>
     */
    private function declaration(Stagiaire $stagiaire): array
    {
        return [
            'cef' => $stagiaire->cef,
            'etablissement_id' => $stagiaire->etablissement_id,
            'nom_filiere' => $stagiaire->nom_filiere,
            'niveau_formation' => $stagiaire->niveau_formation?->value,
            'annee_formation' => $stagiaire->annee_formation?->value,
            'annee_scolaire' => $stagiaire->annee_scolaire,
        ];
    }

    private function reporter(Stagiaire $stagiaire, StatutVerificationInscription $decision): void
    {
        $verifiee = $decision === StatutVerificationInscription::Verifiee;

        $stagiaire->forceFill([
            'statut_inscription' => $decision->versStagiaire(),
            'inscription_verifiee_le' => $verifiee ? now() : null,
        ])->save();
    }

    private function motifValide(StatutVerificationInscription $decision, ?string $motif, bool $obligatoire): ?string
    {
        if (! in_array($decision, StatutVerificationInscription::decisions(), true)) {
            throw new RegleMetierException('Décision inconnue.', 'decision_inconnue');
        }

        $motif = trim((string) $motif);

        if ($obligatoire && mb_strlen($motif) < 5) {
            throw new RegleMetierException('Indiquez le motif de la décision (5 caractères au moins).', 'motif_requis');
        }

        return $motif === '' ? null : mb_substr($motif, 0, 500);
    }

    /**
     * Une inscription remise en cause retire ses dossiers de la sélection : un dossier « complet »
     * repasse en instruction. Après la délibération, la décision appartient au chef de pôle.
     */
    private function rouvrirLesDossiers(Stagiaire $stagiaire): void
    {
        $dossiers = Demande::where('stagiaire_id', $stagiaire->id)->lockForUpdate()->get();

        $engages = $dossiers->filter(fn (Demande $d) => in_array($d->statut, [StatutDemande::Admis, StatutDemande::EnAttente, StatutDemande::Confirme], true));

        if ($engages->isNotEmpty()) {
            throw new RegleMetierException(
                'Ce stagiaire a un dossier déjà délibéré (admis, en attente ou confirmé). Son inscription ne peut plus être remise en cause ici : saisissez le chef de pôle scolarité.',
                'dossier_delibere',
            );
        }

        foreach ($dossiers->where('statut', StatutDemande::Complet) as $dossier) {
            $this->transition->rouvrirInstruction($dossier, 'Inscription en formation remise en cause : dossier à instruire de nouveau');

            RecalculerClassement::dispatch($dossier->campagne_id)->afterCommit();
        }
    }

    private function prevenirLesAgents(Stagiaire $stagiaire, VerificationInscription $verification): void
    {
        $internat = $stagiaire->internat();

        if ($internat === null) {
            return;
        }

        foreach ([RoleCode::Gestionnaire, RoleCode::ChefPole] as $role) {
            $this->notifieur->versRole(
                $role,
                $internat->id,
                TypeNotification::Information,
                'Inscription à vérifier',
                "{$stagiaire->nomComplet()} a soumis son inscription en formation à la vérification.",
                "/inscriptions/{$stagiaire->id}",
                email: false,
            );
        }
    }

    private function prevenirLeStagiaire(VerificationInscription $verification): void
    {
        [$titre, $message] = match ($verification->statut) {
            StatutVerificationInscription::Verifiee => ['Inscription vérifiée', 'Votre inscription en formation a été vérifiée. Vous pouvez poursuivre votre demande d\'hébergement.'],
            StatutVerificationInscription::ACorriger => ['Inscription à corriger', 'Une correction est demandée sur votre inscription : '.$verification->motif],
            StatutVerificationInscription::Rejetee => ['Inscription rejetée', 'Votre inscription en formation n\'a pas pu être vérifiée : '.$verification->motif],
            default => [null, null],
        };

        if ($titre !== null) {
            $this->notifieur->versStagiaire($verification->stagiaire, TypeNotification::ChangementStatut, $titre, $message, '/stagiaire/inscription');
        }
    }
}
