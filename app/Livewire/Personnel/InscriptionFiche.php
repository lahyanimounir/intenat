<?php

namespace App\Livewire\Personnel;

use App\Domain\Identity\ControleInscription;
use App\Enums\StatutInscription;
use App\Enums\StatutVerificationInscription;
use App\Livewire\PagePersonnel;
use App\Models\Stagiaire;
use App\Models\VerificationInscription;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Fiche de vérification d'un stagiaire : ce qu'il déclare, son justificatif,
 * l'historique des décisions, et la décision de l'agent.
 *
 * Une demande en attente se tranche ici (vérifiée, à corriger, rejetée). Sans
 * demande, l'agent peut vérifier sur le registre de scolarité une inscription
 * seulement déclarée, confirmer une reprise MyWay, ou remettre en cause une
 * inscription déjà vérifiée — toujours avec un motif écrit.
 */
#[Title('Vérification d’une inscription')]
class InscriptionFiche extends PagePersonnel
{
    #[Locked]
    public int $stagiaireId;

    /** Identifiant de la vérification dont le justificatif est affiché, ou null. */
    #[Locked]
    public ?int $apercu = null;

    public function mount(Stagiaire $stagiaire): void
    {
        $this->exiger(ControleInscription::PERMISSION);
        // Hors périmètre : la fiche n'existe pas pour ce compte.
        abort_unless(app(ControleInscription::class)->couvre($this->utilisateur(), $stagiaire), 404);
        abort_if($stagiaire->statut_inscription === StatutInscription::NonDeclaree, 404);

        $this->stagiaireId = $stagiaire->id;
    }

    private function stagiaire(): Stagiaire
    {
        $stagiaire = Stagiaire::with('etablissement:id,nom_efp,code_efp')->findOrFail($this->stagiaireId);
        app(ControleInscription::class)->exigerAcces($this->utilisateur(), $stagiaire);

        return $stagiaire;
    }

    private function decision(string $decision): StatutVerificationInscription
    {
        $cas = StatutVerificationInscription::tryFrom($decision);
        abort_unless($cas !== null && in_array($cas, StatutVerificationInscription::decisions(), true), 422);

        return $cas;
    }

    /** Décision sur la demande en attente. */
    public function decider(ControleInscription $controle, string $decision, ?string $motif = null): void
    {
        $stagiaire = $this->stagiaire();
        $demande = VerificationInscription::where('stagiaire_id', $stagiaire->id)->enAttente()->latest('id')->first();

        if ($demande === null) {
            $this->message('Cette demande a déjà été traitée ou retirée par le stagiaire.', 'danger');

            return;
        }

        $cas = $this->decision($decision);

        $this->executer(fn () => $controle->decider($demande, $cas, $motif, $this->utilisateur()), match ($cas) {
            StatutVerificationInscription::Verifiee => 'Inscription vérifiée. Le stagiaire est prévenu.',
            StatutVerificationInscription::ACorriger => 'Correction demandée. Le stagiaire est prévenu.',
            default => 'Inscription rejetée. Le stagiaire est prévenu.',
        });
    }

    /** Décision sans demande en attente : registre de scolarité, confirmation d'une reprise, remise en cause. */
    public function deciderSansDemande(ControleInscription $controle, string $decision, ?string $motif = null): void
    {
        $cas = $this->decision($decision);

        $this->executer(fn () => $controle->deciderSansDemande($this->stagiaire(), $cas, $motif, $this->utilisateur()), match ($cas) {
            StatutVerificationInscription::Verifiee => 'Inscription vérifiée. Le stagiaire est prévenu.',
            StatutVerificationInscription::ACorriger => 'Correction demandée. Le stagiaire est prévenu.',
            default => 'Inscription rejetée. Le stagiaire est prévenu.',
        });
    }

    public function voirJustificatif(int $verification): void
    {
        $existe = VerificationInscription::where('stagiaire_id', $this->stagiaire()->id)->whereKey($verification)->whereNotNull('fichier')->exists();
        abort_unless($existe, 404);

        $this->apercu = $verification;
        $this->ouvrirDialogue('justificatif');
    }

    public function fermerDialogue(): void
    {
        $this->apercu = null;
        $this->dialogue = null;
    }

    public function render()
    {
        $stagiaire = $this->stagiaire();
        $historique = $stagiaire->verificationsInscription()->with('agent:id,nom,prenom', 'etablissement:id,nom_efp,code_efp')->latest('id')->get();

        return view('livewire.personnel.inscription-fiche', [
            'stagiaire' => $stagiaire,
            'historique' => $historique,
            'demande' => $historique->firstWhere('statut', StatutVerificationInscription::Soumise),
            'reprise' => $historique->first()?->origine->value === 'reprise' && $historique->first()?->decidee_par === null,
            'justificatif' => $this->apercu !== null ? $historique->firstWhere('id', $this->apercu) : null,
            'dossiers' => $stagiaire->demandes()->with('campagne:id,annee')->latest('id')->get(['id', 'campagne_id', 'statut', 'numero_dossier']),
            'internat' => $stagiaire->internat(),
        ]);
    }
}
