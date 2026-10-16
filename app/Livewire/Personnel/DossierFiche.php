<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Applications\Instruction;
use App\Domain\Shared\Parametres;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Livewire\PagePersonnel;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\PieceJointe;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Fiche d'un dossier : contrôle des pièces, décision d'instruction (complet,
 * à rectifier, rejet motivé), puis suites de l'admission — dossier papier et
 * permission d'occuper. Chaque action revérifie la permission et le périmètre.
 */
#[Title('Dossier')]
class DossierFiche extends PagePersonnel
{
    #[Locked]
    public int $demandeId;

    #[Locked]
    public ?int $apercu = null;

    public function mount(Demande $demande): void
    {
        $this->consulter($demande);
        $this->demandeId = $demande->id;
    }

    private function consulter(Demande $demande): void
    {
        $this->autoriser($demande, 'demandes.consulter');
        // Un brouillon reste privé au stagiaire.
        abort_if($demande->statut === StatutDemande::Brouillon, 404);
    }

    private function demande(): Demande
    {
        $demande = Demande::findOrFail($this->demandeId);
        $this->consulter($demande);

        return $demande;
    }

    private function piece(int $id): PieceJointe
    {
        return PieceJointe::where('demande_id', $this->demandeId)->findOrFail($id);
    }

    public function accepterPiece(Instruction $instruction, int $piece): void
    {
        $this->autoriser($this->demande(), 'demandes.instruire');

        $this->executer(fn () => $instruction->controlerPiece($this->piece($piece), StatutPiece::Acceptee, null, $this->utilisateur()), 'Pièce acceptée.');
    }

    public function demanderRemplacement(Instruction $instruction, int $piece, ?string $commentaire = null): void
    {
        $this->autoriser($this->demande(), 'demandes.instruire');
        $commentaire = $this->motifExige($commentaire, 3, 500, 'Indiquez au stagiaire ce qui ne convient pas (3 caractères au moins).');

        $this->executer(fn () => $instruction->controlerPiece($this->piece($piece), StatutPiece::ARemplacer, $commentaire, $this->utilisateur()), 'Pièce marquée à remplacer.');
    }

    public function declarerComplet(Instruction $instruction): void
    {
        $demande = $this->demande();
        $this->autoriser($demande, 'demandes.instruire');

        $this->executer(fn () => $instruction->declarerComplet($demande, $this->utilisateur()), 'Dossier déclaré complet. Le stagiaire est prévenu.');
    }

    public function renvoyer(Instruction $instruction, ?string $motif = null): void
    {
        $demande = $this->demande();
        $this->autoriser($demande, 'demandes.instruire');
        $motif = $this->motifExige($motif, 10, 1000, 'Précisez ce que le stagiaire doit corriger (10 caractères au moins).');

        $this->executer(fn () => $instruction->renvoyerPourRectification($demande, $motif, $this->utilisateur()), 'Dossier renvoyé au stagiaire pour rectification.');
    }

    public function rejeter(Instruction $instruction, ?string $motif = null): void
    {
        $demande = $this->demande();
        $this->autoriser($demande, 'demandes.instruire');
        $motif = $this->motifExige($motif, 10, 1000, 'Indiquez le motif du rejet (10 caractères au moins).');

        $this->executer(fn () => $instruction->rejeter($demande, $motif, $this->utilisateur()), 'Dossier rejeté. Le motif est communiqué au stagiaire.');
    }

    public function recevoirDossierPhysique(Instruction $instruction): void
    {
        $demande = $this->demande();
        $this->autoriser($demande, 'hebergement.gerer');

        $this->executer(fn () => $instruction->recevoirDossierPhysique($demande, $this->utilisateur()), 'Dossier physique enregistré.');
    }

    public function autoriserOccupation(AffectationLits $affectations): void
    {
        $demande = $this->demande();
        $this->autoriser($demande, 'hebergement.gerer');

        $this->executer(fn () => $affectations->autoriserOccupation($demande, $this->utilisateur()), 'Occupation autorisée : un lit peut être attribué.');
    }

    public function voirPiece(int $piece): void
    {
        $this->apercu = $this->piece($piece)->id;
        $this->ouvrirDialogue('piece');
    }

    public function fermerDialogue(): void
    {
        $this->apercu = null;
        $this->dialogue = null;
    }

    public function render()
    {
        $demande = $this->demande()->load('stagiaire.etablissement', 'campagne.internat.etablissement', 'instructeur', 'paiements', 'affectationActive.lit.chambre');
        $utilisateur = $this->utilisateur();
        $pieces = app(DossierStagiaire::class)->piecesAttendues($demande);
        $requises = $pieces->filter(fn (array $p) => $p['requise']);
        $enInstruction = $demande->statut === StatutDemande::Recu;
        $instruire = $enInstruction && $utilisateur->can('demandes.instruire');

        return view('livewire.personnel.dossier-fiche', [
            'demande' => $demande,
            'stagiaire' => $demande->stagiaire,
            'pieces' => $pieces,
            'requises' => $requises,
            'acceptees' => $requises->filter(fn (array $p) => $p['piece']?->statut === StatutPiece::Acceptee)->count(),
            'nonControlees' => $pieces->filter(fn (array $p) => $p['piece']?->statut === StatutPiece::Deposee)->count(),
            'aRemplacer' => $pieces->filter(fn (array $p) => $p['piece']?->statut === StatutPiece::ARemplacer)->count(),
            'instruire' => $instruire,
            // Un dossier renvoyé pour rectification peut encore être rejeté.
            'rejetPossible' => $utilisateur->can('demandes.instruire') && in_array($demande->statut, [StatutDemande::Recu, StatutDemande::RetourRectification], true),
            'gererHebergement' => $utilisateur->can('hebergement.gerer'),
            // Le score et le rang sont masqués au gestionnaire pendant l'instruction, sauf réglage contraire.
            'voirClassement' => $utilisateur->can('admissions.consulter')
                || ($utilisateur->can('demandes.instruire') && ! app(Parametres::class)->vrai('instruction.masquer_score_gestionnaire')),
            'historique' => HistoriqueDemande::where('demande_id', $demande->id)->with('utilisateur:id,nom,prenom')->orderByDesc('date_heure')->orderByDesc('id')->get(),
            'obstacles' => $demande->statut === StatutDemande::Confirme && ! $demande->occupationAutorisee()
                ? app(AffectationLits::class)->obstaclesOccupation($demande) : [],
            'pieceAffichee' => $this->apercu !== null ? $pieces->pluck('piece')->filter()->firstWhere('id', $this->apercu) : null,
        ]);
    }
}
