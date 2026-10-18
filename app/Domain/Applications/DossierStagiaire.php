<?php

namespace App\Domain\Applications;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\DeclencheurCritere;
use App\Enums\NiveauFormation;
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
    ) {}

    /**
     * Campagne accessible de l'internat correspondant au genre du stagiaire : ouverte par le chef de
     * pôle et non échue, même avant sa date d'ouverture. S'il y en a plusieurs, la plus proche.
     */
    public function campagneOuverte(Stagiaire $stagiaire): ?Campagne
    {
        $internat = $stagiaire->internat();

        if ($internat === null) {
            return null;
        }

        return Campagne::query()->accessibles()
            ->where('internat_id', $internat->id)
            ->orderBy('date_ouverture')
            ->first();
    }

    /** @param  Campagne|null  $choisie  Campagne retenue par le candidat ; à défaut, la plus proche. */
    public function ouvrirBrouillon(Stagiaire $stagiaire, ?Campagne $choisie = null): Demande
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

        $campagne = $choisie ?? $this->campagneOuverte($stagiaire);

        // Une campagne choisie doit être celle de l'internat du stagiaire, et recevoir les candidatures.
        if ($choisie !== null && ($choisie->internat_id !== $stagiaire->internat()?->id || ! $choisie->accepteLesDepots())) {
            $campagne = null;
        }

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

            $this->exigerAucuneCandidatureEnCours($stagiaire, $campagne);

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

        return $this->piecesRequises($demande->campagne, [
            'eloigne_ou_rural' => (bool) $demande->eloigne_ou_rural,
            'demuni' => (bool) $demande->demuni,
            'orphelin_ou_divorce' => (bool) $demande->orphelin_ou_divorce,
            'besoins_specifiques' => (bool) $demande->besoins_specifiques,
        ], $demande->stagiaire->niveau_formation)
            ->map(fn (array $p) => $p + ['piece' => $demande->pieces->firstWhere('type_piece_id', $p['type']->id)]);
    }

    /**
     * Pièces à fournir calculées à partir d'une campagne et des déclarations de situation,
     * sans dossier existant : sert au parcours d'inscription avant la création du compte.
     *
     * @param  array<string, bool>  $declarations  clés : eloigne_ou_rural, demuni, orphelin_ou_divorce, besoins_specifiques
     * @return Collection<int, array{type: TypePiece, requise: bool, raison: string|null}>
     */
    public function piecesRequises(Campagne $campagne, array $declarations, ?NiveauFormation $niveau): Collection
    {
        $campagne->loadMissing('typesPieces', 'criteres');
        $liees = $this->liensCriteres($campagne, $declarations, $niveau);

        return $campagne->typesPieces->map(fn (TypePiece $type) => [
            'type' => $type,
            'requise' => $type->obligatoire || isset($liees[$type->id]),
            'raison' => $type->obligatoire ? null : ($liees[$type->id] ?? null),
        ])->values();
    }

    /**
     * Le stagiaire retire sa candidature reçue pour la modifier : elle repasse en
     * brouillon, modifiable, sans changer de numéro de dossier (pas de doublon).
     */
    public function retirer(Demande $demande): Demande
    {
        if ($demande->statut !== StatutDemande::Recu) {
            throw new RegleMetierException(
                'Seule une candidature reçue et non encore instruite peut être retirée.',
                'retrait_impossible',
            );
        }

        return $this->transition->appliquer($demande, StatutDemande::Brouillon, 'Retrait par le stagiaire pour modification');
    }

    /** Soumission initiale ou nouvelle soumission après rectification ou retrait. */
    public function soumettre(Demande $demande): Demande
    {
        $this->exigerModifiable($demande);

        return DB::transaction(function () use ($demande) {
            $campagne = Campagne::lockForUpdate()->findOrFail($demande->campagne_id);
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $premiere = $demande->statut === StatutDemande::Brouillon;
            // Un brouillon déjà numéroté est une candidature retirée puis re-soumise : on réutilise son numéro.
            $toutePremiere = $premiere && $demande->numero_dossier === null;

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
                $this->exigerAucuneCandidatureEnCours($demande->stagiaire, $campagne, $demande);
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

            if ($toutePremiere) {
                $this->attribuerNumeroDOrdre($campagne);
                $attributs['numero_dossier'] = $this->numeroDossier($campagne);
            }

            if ($premiere) {
                $attributs['date_soumission'] = now();
            }

            $this->transition->appliquer(
                $demande,
                StatutDemande::Recu,
                $toutePremiere ? 'Dépôt du dossier' : 'Nouvelle soumission',
                $attributs,
            );

            $stagiaire = $demande->stagiaire;

            $this->notifieur->versStagiaire(
                $stagiaire,
                TypeNotification::ChangementStatut,
                $toutePremiere ? 'Dossier reçu' : 'Dossier soumis de nouveau',
                $toutePremiere
                    ? "Votre dossier n° {$demande->numero_dossier} a bien été reçu. L'accusé de réception est disponible dans votre espace."
                    : "Votre dossier n° {$demande->numero_dossier} a été soumis de nouveau pour instruction.",
                '/stagiaire/dossier',
            );

            $this->notifieur->versRole(
                RoleCode::Gestionnaire,
                $campagne->internat_id,
                TypeNotification::ChangementStatut,
                $toutePremiere ? 'Nouveau dossier à instruire' : 'Dossier mis à jour à instruire',
                "Dossier n° {$demande->numero_dossier} — {$stagiaire->nomComplet()}.",
                "/dossiers/{$demande->id}",
                email: false,
            );

            return $demande;
        });
    }

    /**
     * Candidature unifiée : l'inscription en formation et le dossier sont soumis ensemble, puis le
     * gestionnaire les examine en une seule revue. L'inscription est donc « soumise » (non encore
     * vérifiée) au moment du dépôt, ce qui est normal ; seuls une correction demandée ou un rejet
     * empêchent la soumission, le temps que le candidat corrige son inscription.
     */
    private function exigerInscription(Stagiaire $stagiaire): void
    {
        $statut = $stagiaire->statut_inscription;

        if (in_array($statut, [StatutInscription::Verifiee, StatutInscription::Soumise], true)) {
            return;
        }

        throw new RegleMetierException(
            match ($statut) {
                StatutInscription::ACorriger => 'Une correction est demandée sur votre inscription en formation : corrigez-la et soumettez-la de nouveau avant de déposer votre dossier.',
                StatutInscription::Rejetee => 'Votre inscription en formation a été rejetée : le dossier ne peut pas être déposé.',
                default => 'Soumettez d\'abord votre inscription en formation à la vérification, avec son justificatif.',
            },
            'inscription_non_verifiee',
        );
    }

    /** Statuts d'un dossier encore en cours : instruit, retenu ou en attente d'une place. */
    public const CANDIDATURE_EN_COURS = [
        StatutDemande::Recu, StatutDemande::RetourRectification, StatutDemande::Complet,
        StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::EnAttente,
    ];

    /**
     * Plusieurs campagnes peuvent se succéder la même année scolaire : un stagiaire n'y candidate de
     * nouveau que si son dossier précédent est clos (rejeté, refusé, désisté, délai dépassé, séjour terminé).
     */
    private function exigerAucuneCandidatureEnCours(Stagiaire $stagiaire, Campagne $campagne, ?Demande $saufCelleCi = null): void
    {
        $enCours = Demande::where('stagiaire_id', $stagiaire->id)
            ->when($saufCelleCi, fn ($q) => $q->whereKeyNot($saufCelleCi->id))
            ->whereIn('statut', array_map(fn (StatutDemande $s) => $s->value, self::CANDIDATURE_EN_COURS))
            ->whereHas('campagne', fn ($q) => $q->where('annee', $campagne->annee))
            ->with('campagne:id,annee,date_ouverture,date_cloture')
            ->first();

        if ($enCours !== null) {
            throw new RegleMetierException(
                "Vous avez déjà une candidature en cours pour l'année {$campagne->annee} (dossier {$enCours->numero_dossier}, "
                ."{$enCours->statut->libelle()}). Une nouvelle candidature n'est possible qu'une fois celle-ci close.",
                'candidature_en_cours',
            );
        }
    }

    /**
     * Numéro d'ordre du dossier : il se poursuit d'une campagne à l'autre pour un même internat et une même
     * année, afin que deux campagnes de l'année ne produisent jamais le même numéro de dossier.
     */
    private function attribuerNumeroDOrdre(Campagne $campagne): void
    {
        $dernier = (int) Campagne::where('internat_id', $campagne->internat_id)->where('annee', $campagne->annee)
            ->lockForUpdate()->max('compteur_dossiers');

        $campagne->forceFill(['compteur_dossiers' => $dernier + 1])->save();
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
     * @param  array<string, bool>  $declarations
     * @return array<int, string> type_piece_id => libellé de la déclaration qui l'exige
     */
    private function liensCriteres(Campagne $campagne, array $declarations, ?NiveauFormation $niveau): array
    {
        $liees = [];

        foreach ($campagne->criteres as $critere) {
            if ($critere->type_piece_id === null) {
                continue;
            }

            $declare = match ($critere->declencheur) {
                DeclencheurCritere::EloigneOuRural => (bool) ($declarations['eloigne_ou_rural'] ?? false),
                DeclencheurCritere::Demuni => (bool) ($declarations['demuni'] ?? false),
                DeclencheurCritere::OrphelinOuDivorce => (bool) ($declarations['orphelin_ou_divorce'] ?? false),
                DeclencheurCritere::BesoinsSpecifiques => (bool) ($declarations['besoins_specifiques'] ?? false),
                DeclencheurCritere::NiveauFormation => $niveau?->value === $critere->valeur_attendue,
            };

            if ($declare) {
                $liees[$critere->type_piece_id] = $critere->condition_texte;
            }
        }

        return $liees;
    }
}
