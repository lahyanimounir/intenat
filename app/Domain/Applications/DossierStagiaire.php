<?php

namespace App\Domain\Applications;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\DeclencheurCritere;
use App\Enums\RoleCode;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Enums\StatutPiece;
use App\Enums\TypeNotification;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\PieceJointe;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Préinscription côté stagiaire : brouillon, pièces justificatives, soumission
 * (contrôle du délai et de la complétude, accusé et numéro de dossier) et
 * nouvelle soumission après une demande de rectification.
 */
class DossierStagiaire
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly Documents $documents,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
        private readonly Parametres $parametres,
    ) {}

    /** Campagne ouverte de l'internat correspondant au genre du stagiaire. */
    public function campagneOuverte(Stagiaire $stagiaire): ?Campagne
    {
        $internat = $stagiaire->internat();

        if ($internat === null) {
            return null;
        }

        return Campagne::query()
            ->where('internat_id', $internat->id)
            ->where('statut', 'ouverte')
            ->whereDate('date_ouverture', '<=', now())
            ->whereDate('date_cloture', '>=', now())
            ->orderByDesc('date_ouverture')
            ->first();
    }

    public function ouvrirBrouillon(Stagiaire $stagiaire): Demande
    {
        if (! $stagiaire->profilComplet()) {
            throw new RegleMetierException(
                'Complétez d\'abord votre profil : votre identité et votre formation sont requises.',
                'profil_incomplet',
            );
        }

        if ($stagiaire->statut_inscription === StatutInscription::Rejetee) {
            throw new RegleMetierException(
                'Votre inscription en formation a été rejetée : corrigez-la et soumettez-la de nouveau avant d\'ouvrir un dossier d\'hébergement.',
                'inscription_rejetee',
            );
        }

        $campagne = $this->campagneOuverte($stagiaire);

        if ($campagne === null) {
            throw new RegleMetierException(
                'Aucune campagne n\'est ouverte pour votre internat actuellement.',
                'aucune_campagne',
            );
        }

        return DB::transaction(function () use ($stagiaire, $campagne) {
            $existante = Demande::where('campagne_id', $campagne->id)
                ->where('stagiaire_id', $stagiaire->id)->lockForUpdate()->first();

            if ($existante !== null) {
                return $existante;
            }

            $demande = Demande::create([
                'campagne_id' => $campagne->id,
                'stagiaire_id' => $stagiaire->id,
                'statut' => StatutDemande::Brouillon,
            ]);

            $this->journal->consigner('demande.creation', $demande);

            return $demande;
        });
    }

    /** @param array<string, mixed> $donnees */
    public function enregistrer(Demande $demande, array $donnees): Demande
    {
        $this->exigerModifiable($demande);

        $demande->fill(collect($donnees)->only([
            'adresse_complete', 'eloigne_ou_rural', 'demuni', 'orphelin_ou_divorce',
            'besoins_specifiques', 'precisions_besoins',
        ])->all());

        if (! $demande->besoins_specifiques) {
            $demande->precisions_besoins = null;
        }

        $demande->save();

        return $demande;
    }

    public function deposerPiece(Demande $demande, TypePiece $type, UploadedFile $fichier): PieceJointe
    {
        $this->exigerModifiable($demande);

        if ($type->campagne_id !== $demande->campagne_id) {
            throw new RegleMetierException('Cette pièce n\'est pas demandée pour cette campagne.', 'piece_hors_campagne');
        }

        $regles = $this->documents->regles($type->formats_acceptes);
        $stocke = $this->documents->enregistrer($fichier, "pieces/{$demande->id}", $regles['extensions'], $regles['taille_max_ko']);

        return DB::transaction(function () use ($demande, $type, $stocke) {
            $piece = PieceJointe::where('demande_id', $demande->id)
                ->where('type_piece_id', $type->id)->lockForUpdate()->first();

            if ($piece?->statut === StatutPiece::Acceptee) {
                $this->documents->supprimer($stocke['chemin']);

                throw new RegleMetierException('Cette pièce a déjà été acceptée et ne peut plus être remplacée.', 'piece_acceptee');
            }

            $ancien = $piece?->fichier;

            $piece ??= new PieceJointe(['demande_id' => $demande->id, 'type_piece_id' => $type->id]);
            $piece->fill([
                'fichier' => $stocke['chemin'],
                'nom_original' => $stocke['nom_original'],
                'mime' => $stocke['mime'],
                'taille' => $stocke['taille'],
                'statut' => StatutPiece::Deposee,
                'date_depot' => now(),
                'commentaire' => null,
                'controle_par' => null,
                'date_controle' => null,
            ])->save();

            DB::afterCommit(fn () => $this->documents->supprimer($ancien));

            return $piece;
        });
    }

    public function retirerPiece(Demande $demande, PieceJointe $piece): void
    {
        $this->exigerModifiable($demande);

        if ($piece->demande_id !== $demande->id || $piece->statut === StatutPiece::Acceptee) {
            throw new RegleMetierException('Cette pièce ne peut pas être retirée.', 'piece_verrouillee');
        }

        $chemin = $piece->fichier;
        $piece->delete();
        $this->documents->supprimer($chemin);
    }

    /**
     * Pièces à fournir : celles exigées de tous, plus celles liées à un critère déclaré.
     *
     * @return Collection<int, array{type: TypePiece, requise: bool, raison: string|null, piece: PieceJointe|null}>
     */
    public function piecesAttendues(Demande $demande): Collection
    {
        $demande->loadMissing('campagne.typesPieces', 'campagne.criteres', 'pieces', 'stagiaire');
        $declarees = $this->piecesLieesAuxDeclarations($demande);

        return $demande->campagne->typesPieces->map(function (TypePiece $type) use ($demande, $declarees) {
            $raison = $declarees[$type->id] ?? null;

            return [
                'type' => $type,
                'requise' => $type->obligatoire || $raison !== null,
                'raison' => $type->obligatoire ? null : $raison,
                'piece' => $demande->pieces->firstWhere('type_piece_id', $type->id),
            ];
        })->values();
    }

    /** Soumission initiale ou nouvelle soumission après rectification. */
    public function soumettre(Demande $demande): Demande
    {
        $this->exigerModifiable($demande);

        return DB::transaction(function () use ($demande) {
            $campagne = Campagne::lockForUpdate()->findOrFail($demande->campagne_id);
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $premiere = $demande->statut === StatutDemande::Brouillon;

            if ($premiere && ! $campagne->accepteLesDepots()) {
                throw new RegleMetierException(
                    'Le dépôt des dossiers est clos pour cette campagne.',
                    'hors_delai',
                );
            }

            if (! $premiere && $campagne->estDeliberee()) {
                throw new RegleMetierException(
                    'Les admissions de cette campagne sont déjà validées : le dossier ne peut plus être rectifié.',
                    'campagne_deliberee',
                );
            }

            if ($premiere) {
                $this->exigerInscription($demande->stagiaire);
            }

            if (blank($demande->adresse_complete)) {
                throw new RegleMetierException('Renseignez votre adresse complète avant de soumettre.', 'adresse_manquante');
            }

            $manquantes = $this->piecesAttendues($demande)
                ->filter(fn (array $p) => $p['requise'] && ($p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer))
                ->map(fn (array $p) => $p['type']->libelle)->values();

            if ($manquantes->isNotEmpty()) {
                throw new RegleMetierException(
                    'Pièces manquantes ou à remplacer : '.$manquantes->implode(', ').'.',
                    'pieces_manquantes',
                    ['pieces' => $manquantes->all()],
                );
            }

            $attributs = ['motif_rectification' => null];

            if ($premiere) {
                $campagne->increment('compteur_dossiers');
                $attributs['numero_dossier'] = $this->numeroDossier($campagne);
                $attributs['date_soumission'] = now();
            }

            $this->transition->appliquer(
                $demande,
                StatutDemande::Recu,
                $premiere ? 'Dépôt du dossier' : 'Nouvelle soumission après rectification',
                $attributs,
            );

            $stagiaire = $demande->stagiaire;

            $this->notifieur->versStagiaire(
                $stagiaire,
                TypeNotification::ChangementStatut,
                $premiere ? 'Dossier reçu' : 'Dossier soumis de nouveau',
                $premiere
                    ? "Votre dossier n° {$demande->numero_dossier} a bien été reçu. L'accusé de réception est disponible dans votre espace."
                    : "Votre dossier n° {$demande->numero_dossier} a été soumis de nouveau pour instruction.",
                '/stagiaire/dossier',
            );

            $this->notifieur->versRole(
                RoleCode::Gestionnaire,
                $campagne->internat_id,
                TypeNotification::ChangementStatut,
                $premiere ? 'Nouveau dossier à instruire' : 'Dossier rectifié à instruire',
                "Dossier n° {$demande->numero_dossier} — {$stagiaire->nomComplet()}.",
                "/dossiers/{$demande->id}",
                email: false,
            );

            return $demande;
        });
    }

    /**
     * Le dépôt suppose une inscription en formation soumise à la vérification — ou déjà vérifiée, si le
     * paramètre l'exige. Un dossier déposé ne participe de toute façon à la sélection qu'une fois
     * l'inscription vérifiée : il ne peut pas être déclaré complet avant (voir Instruction).
     */
    private function exigerInscription(Stagiaire $stagiaire): void
    {
        $statut = $stagiaire->statut_inscription;

        if ($statut === StatutInscription::Verifiee) {
            return;
        }

        if ($statut === StatutInscription::Soumise && ! $this->parametres->vrai('inscription.depot_exige_verification')) {
            return;
        }

        throw new RegleMetierException(
            match ($statut) {
                StatutInscription::Soumise => 'Votre inscription en formation est en cours de vérification : vous pourrez déposer votre dossier dès qu\'elle sera vérifiée.',
                StatutInscription::ACorriger => 'Une correction est demandée sur votre inscription en formation : corrigez-la et soumettez-la de nouveau avant de déposer votre dossier.',
                StatutInscription::Rejetee => 'Votre inscription en formation a été rejetée : le dossier ne peut pas être déposé.',
                default => 'Soumettez d\'abord votre inscription en formation à la vérification, avec son justificatif.',
            },
            'inscription_non_verifiee',
        );
    }

    private function exigerModifiable(Demande $demande): void
    {
        if (! $demande->estModifiableParLeStagiaire()) {
            throw new RegleMetierException(
                'Ce dossier n\'est plus modifiable à ce stade de son instruction.',
                'dossier_verrouille',
            );
        }
    }

    /** INT-2026-LC10-F-00042 : année de début, code EFP, genre, numéro d'ordre. */
    private function numeroDossier(Campagne $campagne): string
    {
        $campagne->loadMissing('internat.etablissement');

        return sprintf(
            'INT-%s-%s-%s-%05d',
            substr($campagne->annee, 0, 4),
            $campagne->internat->etablissement->code_efp,
            $campagne->internat->genre->value === 'fille' ? 'F' : 'G',
            $campagne->compteur_dossiers,
        );
    }

    /**
     * @return array<int, string> type_piece_id => libellé de la déclaration qui l'exige
     */
    private function piecesLieesAuxDeclarations(Demande $demande): array
    {
        $liees = [];

        foreach ($demande->campagne->criteres as $critere) {
            if ($critere->type_piece_id === null) {
                continue;
            }

            $declare = match ($critere->declencheur) {
                DeclencheurCritere::EloigneOuRural => (bool) $demande->eloigne_ou_rural,
                DeclencheurCritere::Demuni => (bool) $demande->demuni,
                DeclencheurCritere::OrphelinOuDivorce => (bool) $demande->orphelin_ou_divorce,
                DeclencheurCritere::BesoinsSpecifiques => (bool) $demande->besoins_specifiques,
                DeclencheurCritere::NiveauFormation => $demande->stagiaire->niveau_formation?->value === $critere->valeur_attendue,
            };

            if ($declare) {
                $liees[$critere->type_piece_id] = $critere->condition_texte;
            }
        }

        return $liees;
    }
}
