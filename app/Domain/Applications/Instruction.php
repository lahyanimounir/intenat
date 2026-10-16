<?php

namespace App\Domain\Applications;

use App\Domain\Admissions\CalculScore;
use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Enums\TypeNotification;
use App\Jobs\RecalculerClassement;
use App\Models\Demande;
use App\Models\PieceJointe;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Instruction du dossier par le gestionnaire : contrôle des pièces puis
 * décision — complet, à rectifier, ou rejet motivé. Un dossier déclaré
 * complet n'est plus modifiable par le gestionnaire.
 */
class Instruction
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly DossierStagiaire $dossier,
        private readonly CalculScore $calcul,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
    ) {}

    public function controlerPiece(PieceJointe $piece, StatutPiece $statut, ?string $commentaire, Utilisateur $gestionnaire): PieceJointe
    {
        if ($statut === StatutPiece::Deposee) {
            throw new RegleMetierException('Décision de contrôle invalide.', 'decision_invalide');
        }

        if ($statut === StatutPiece::ARemplacer && blank($commentaire)) {
            throw new RegleMetierException('Indiquez au stagiaire pourquoi la pièce doit être remplacée.', 'commentaire_requis');
        }

        return DB::transaction(function () use ($piece, $statut, $commentaire, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($piece->demande_id);
            $this->exigerEnInstruction($demande);

            $avant = $piece->statut->value;
            $piece->fill([
                'statut' => $statut,
                'commentaire' => $commentaire,
                'controle_par' => $gestionnaire->id,
                'date_controle' => now(),
            ])->save();

            $this->journal->consigner('piece.controle', $piece, details: [
                'avant' => $avant, 'apres' => $statut->value, 'commentaire' => $commentaire,
            ]);

            return $piece;
        });
    }

    public function declarerComplet(Demande $demande, Utilisateur $gestionnaire): Demande
    {
        return DB::transaction(function () use ($demande, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $this->exigerEnInstruction($demande);

            // Seule une inscription en formation vérifiée par un agent ouvre la sélection.
            if (! $demande->stagiaire->inscriptionVerifiee()) {
                throw new RegleMetierException(
                    'Le dossier ne peut pas être déclaré complet : l\'inscription en formation du stagiaire n\'est pas vérifiée ('
                        .mb_strtolower($demande->stagiaire->statut_inscription->libelle()).').',
                    'inscription_non_verifiee',
                );
            }

            $attendues = $this->dossier->piecesAttendues($demande);

            $nonConformes = $attendues
                ->filter(fn (array $p) => $p['requise'] && $p['piece']?->statut !== StatutPiece::Acceptee)
                ->map(fn (array $p) => $p['type']->libelle);

            if ($nonConformes->isNotEmpty()) {
                throw new RegleMetierException(
                    'Le dossier ne peut pas être déclaré complet : pièces non acceptées — '.$nonConformes->implode(', ').'.',
                    'pieces_non_acceptees',
                    ['pieces' => $nonConformes->values()->all()],
                );
            }

            $nonControlees = $attendues
                ->filter(fn (array $p) => $p['piece']?->statut === StatutPiece::Deposee)
                ->map(fn (array $p) => $p['type']->libelle);

            if ($nonControlees->isNotEmpty()) {
                throw new RegleMetierException(
                    'Contrôlez toutes les pièces déposées avant de conclure : '.$nonControlees->implode(', ').'.',
                    'pieces_non_controlees',
                );
            }

            $this->transition->appliquer($demande, StatutDemande::Complet, 'Dossier complet', [
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
                'motif_rectification' => null,
            ]);

            // Le score n'est calculé que pour un dossier déclaré complet.
            $this->calcul->calculer($demande->fresh());

            RecalculerClassement::dispatch($demande->campagne_id)->afterCommit();

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::ChangementStatut,
                'Dossier complet',
                "Votre dossier n° {$demande->numero_dossier} est complet. Il participera à la sélection.",
                '/stagiaire/dossier',
            );

            return $demande;
        });
    }

    public function renvoyerPourRectification(Demande $demande, string $motif, Utilisateur $gestionnaire): Demande
    {
        return DB::transaction(function () use ($demande, $motif, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $this->exigerEnInstruction($demande);

            if ($demande->campagne->estDeliberee()) {
                throw new RegleMetierException(
                    'Les admissions sont validées : un renvoi pour rectification n\'est plus possible.',
                    'campagne_deliberee',
                );
            }

            $this->transition->appliquer($demande, StatutDemande::RetourRectification, $motif, [
                'motif_rectification' => $motif,
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
            ]);

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::ChangementStatut,
                'Rectification demandée',
                "Votre dossier n° {$demande->numero_dossier} doit être rectifié : {$motif}",
                '/stagiaire/dossier',
            );

            return $demande;
        });
    }

    public function rejeter(Demande $demande, string $motif, Utilisateur $gestionnaire): Demande
    {
        return DB::transaction(function () use ($demande, $motif, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if (! in_array($demande->statut, [StatutDemande::Recu, StatutDemande::RetourRectification], true)) {
                throw new RegleMetierException('Seul un dossier en cours d\'instruction peut être rejeté.', 'dossier_verrouille');
            }

            $this->transition->appliquer($demande, StatutDemande::Rejete, $motif, [
                'motif_rejet' => $motif,
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
            ]);

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::ChangementStatut,
                'Dossier rejeté',
                "Votre dossier n° {$demande->numero_dossier} a été rejeté. Motif : {$motif}",
                '/stagiaire/dossier',
            );

            return $demande;
        });
    }

    /** Le gestionnaire atteste la réception et le contrôle du dossier papier d'un candidat admis. */
    public function recevoirDossierPhysique(Demande $demande, Utilisateur $gestionnaire): Demande
    {
        return DB::transaction(function () use ($demande, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if (! in_array($demande->statut, [StatutDemande::Admis, StatutDemande::Confirme], true)) {
                throw new RegleMetierException('Le dossier physique ne concerne que les candidats admis.', 'non_admis');
            }

            if ($demande->dossier_physique_depose) {
                throw RegleMetierException::conflit('Le dossier physique est déjà enregistré.');
            }

            $demande->forceFill([
                'dossier_physique_depose' => true,
                'date_depot_physique' => now(),
                'dossier_physique_valide_par' => $gestionnaire->id,
            ])->save();

            $this->journal->consigner('demande.dossier_physique', $demande, acteur: $gestionnaire);

            return $demande;
        });
    }

    private function exigerEnInstruction(Demande $demande): void
    {
        if ($demande->statut !== StatutDemande::Recu) {
            throw new RegleMetierException(
                'Ce dossier n\'est pas en cours d\'instruction (statut : '.$demande->statut->libelle().').',
                'dossier_verrouille',
            );
        }
    }
}
