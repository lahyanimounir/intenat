<?php

namespace App\Domain\Applications;

use App\Domain\Admissions\CalculScore;
use App\Domain\Admissions\Classement;
use App\Domain\Audit\Journal;
use App\Domain\Identity\ControleInscription;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Enums\StatutVerificationInscription;
use App\Enums\TypeNotification;
use App\Models\Demande;
use App\Models\PieceJointe;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Models\VerificationInscription;
use Illuminate\Support\Facades\DB;

/**
 * Instruction de la candidature par le gestionnaire : revue unique du dossier
 * (identité, formation, situation, pièces et justificatif de scolarité) puis
 * décision — complet, à rectifier, ou rejet motivé. Cette décision vaut aussi
 * pour l'inscription en formation : déclarer complet la valide (et ouvre le
 * séjour), un retour pour rectification la met « à corriger », un rejet la
 * rejette. Un dossier déclaré complet n'est plus modifiable par le gestionnaire.
 */
class Instruction
{
    public function __construct(
        private readonly TransitionDemande $transition,
        private readonly DossierStagiaire $dossier,
        private readonly CalculScore $calcul,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
        private readonly ControleInscription $controle,
        private readonly Classement $classement,
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

            // Revue unique : valider le dossier vaut validation de l'inscription en
            // formation du candidat — c'est elle qui ouvre son séjour.
            $this->reporterSurInscription($demande->stagiaire, StatutVerificationInscription::Verifiee, null, $gestionnaire);

            $this->transition->appliquer($demande, StatutDemande::Complet, 'Dossier complet', [
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
                'motif_rectification' => null,
            ]);

            // Le score n'est calculé que pour un dossier déclaré complet ; le candidat entre aussitôt au classement.
            $this->calcul->calculer($demande->fresh());

            $this->classement->actualiser($demande->campagne_id);

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

            // Renvoyer la candidature au candidat : l'inscription repasse « à corriger ».
            $this->reporterSurInscription($demande->stagiaire, StatutVerificationInscription::ACorriger, $motif, $gestionnaire);

            $this->transition->appliquer($demande, StatutDemande::RetourRectification, $motif, [
                'motif_rectification' => $motif,
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
            ]);

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::ChangementStatut,
                'Rectification demandée',
                "Votre candidature n° {$demande->numero_dossier} doit être rectifiée : {$motif}",
                '/stagiaire/inscription',
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

            // Rejeter la candidature rejette aussi l'inscription en formation.
            $this->reporterSurInscription($demande->stagiaire, StatutVerificationInscription::Rejetee, $motif, $gestionnaire);

            $this->transition->appliquer($demande, StatutDemande::Rejete, $motif, [
                'motif_rejet' => $motif,
                'instruit_par' => $gestionnaire->id,
                'date_instruction' => now(),
            ]);

            $this->notifieur->versStagiaire(
                $demande->stagiaire,
                TypeNotification::ChangementStatut,
                'Candidature rejetée',
                "Votre candidature n° {$demande->numero_dossier} a été rejetée. Motif : {$motif}",
                '/stagiaire/inscription',
            );

            return $demande;
        });
    }

    /**
     * Le chef de pôle ou le directeur annule la validation d'une candidature (même après la
     * clôture de la campagne) : le dossier retourne en instruction chez le gestionnaire et
     * l'inscription repasse « en attente ». Le gestionnaire pourra ensuite la renvoyer au
     * stagiaire pour correction. Un dossier « complet » n'existant qu'avant la délibération,
     * l'opération ne concerne jamais un candidat déjà admis.
     */
    public function annulerValidation(Demande $demande, string $motif, Utilisateur $auteur): Demande
    {
        return DB::transaction(function () use ($demande, $motif, $auteur) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);

            if ($demande->statut !== StatutDemande::Complet) {
                throw new RegleMetierException(
                    'Seule une candidature validée peut être renvoyée au gestionnaire (statut : '.$demande->statut->libelle().').',
                    'non_valide',
                );
            }

            $stagiaire = $demande->stagiaire;

            // L'inscription repasse « en attente de validation » : le séjour se referme en attendant le nouvel examen.
            $this->controle->remettreEnAttente($stagiaire, $motif, $auteur);

            // Le dossier retourne en instruction (score, rang et liste réinitialisés).
            $this->transition->rouvrirInstruction($demande, 'Validation annulée : '.$motif);

            $this->notifieur->versRole(
                RoleCode::Gestionnaire,
                $demande->campagne->internat_id,
                TypeNotification::ChangementStatut,
                'Validation annulée',
                "Le dossier n° {$demande->numero_dossier} — {$stagiaire->nomComplet()} — vous est renvoyé pour un nouvel examen : {$motif}",
                "/dossiers/{$demande->id}",
                email: false,
            );

            $this->classement->actualiser($demande->campagne_id);

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

    /**
     * Reporte la décision du gestionnaire sur l'inscription en formation du
     * candidat : elle résout la vérification en attente (soumise avec la
     * candidature), ou, à défaut, tranche directement. La revue du dossier et
     * celle de l'inscription n'en font ainsi qu'une seule.
     */
    private function reporterSurInscription(Stagiaire $stagiaire, StatutVerificationInscription $decision, ?string $motif, Utilisateur $gestionnaire): void
    {
        if ($stagiaire->statut_inscription === $decision->versStagiaire()) {
            return;
        }

        $enAttente = VerificationInscription::where('stagiaire_id', $stagiaire->id)->enAttente()->latest('id')->first();

        if ($enAttente !== null) {
            $this->controle->decider($enAttente, $decision, $motif, $gestionnaire);

            return;
        }

        $this->controle->deciderSansDemande(
            $stagiaire,
            $decision,
            $motif ?? 'Candidature validée par le gestionnaire après contrôle des pièces.',
            $gestionnaire,
        );
    }
}
