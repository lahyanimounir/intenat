<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Shared\Documents;
use App\Enums\RaisonDesistement;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Livewire\PageStagiaire;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\PieceJointe;
use App\Models\TypePiece;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;

/**
 * « Mon dossier » : la demande de place à la Maison des Stagiaires.
 *
 * Tant que le dossier est modifiable (brouillon ou retour pour rectification),
 * l'écran déroule le dépôt en quatre étapes — informations, situation, pièces,
 * envoi. Ensuite, il devient un suivi : décision, confirmation de la place,
 * désistement, historique. Le dossier est toujours celui du compte connecté.
 */
#[Title('Mon dossier')]
class Dossier extends PageStagiaire
{
    use WithFileUploads;

    #[Url(as: 'etape', except: 0)]
    public int $etape = 0;

    /** @var array<string, mixed> */
    public array $situation = [
        'adresse_complete' => '', 'eloigne_ou_rural' => false, 'demuni' => false, 'orphelin_ou_divorce' => false,
        'besoins_specifiques' => false, 'precisions_besoins' => '',
    ];

    /** Fichiers en cours de dépôt, par identifiant de type de pièce. */
    public array $fichiers = [];

    #[Locked]
    public ?int $apercu = null;

    public string $raison = 'eloignement';

    public string $explication = '';

    public function mount(): void
    {
        $demande = $this->demande();

        if ($demande === null) {
            return;
        }

        $this->reprendre($demande);

        // Un dossier renvoyé pour rectification s'ouvre directement sur les pièces.
        if ($demande->statut === StatutDemande::RetourRectification && request()->query('etape') === null) {
            $this->etape = 2;
        }
    }

    private function demande(): ?Demande
    {
        return $this->stagiaire()->demandeCourante();
    }

    private function demandeExigee(): Demande
    {
        $demande = $this->demande();
        abort_if($demande === null, 404);

        return $demande;
    }

    private function reprendre(Demande $demande): void
    {
        $this->situation = [
            // L'adresse déclarée à l'inscription est proposée : elle n'a pas à être saisie une seconde fois.
            'adresse_complete' => (string) ($demande->adresse_complete ?: $this->stagiaire()->adresse),
            'eloigne_ou_rural' => (bool) $demande->eloigne_ou_rural,
            'demuni' => (bool) $demande->demuni,
            'orphelin_ou_divorce' => (bool) $demande->orphelin_ou_divorce,
            'besoins_specifiques' => (bool) $demande->besoins_specifiques,
            'precisions_besoins' => (string) $demande->precisions_besoins,
        ];
    }

    public function ouvrir(DossierStagiaire $dossiers): void
    {
        if ($this->executer(fn () => $this->reprendre($dossiers->ouvrirBrouillon($this->stagiaire())))) {
            $this->etape = 0;
        }
    }

    public function allerA(int $etape): void
    {
        $this->etape = max(0, min(3, $etape));
    }

    public function enregistrer(DossierStagiaire $dossiers, bool $suivant = false): void
    {
        $valides = $this->validate([
            'situation.adresse_complete' => [$suivant ? 'required' : 'nullable', 'string', 'max:500'],
            'situation.eloigne_ou_rural' => ['boolean'],
            'situation.demuni' => ['boolean'],
            'situation.orphelin_ou_divorce' => ['boolean'],
            'situation.besoins_specifiques' => ['boolean'],
            'situation.precisions_besoins' => ['nullable', 'string', 'max:500'],
        ], attributes: ['situation.adresse_complete' => 'adresse complète', 'situation.precisions_besoins' => 'précisions'])['situation'];

        if ($this->executer(fn () => $dossiers->enregistrer($this->demandeExigee(), $valides), $suivant ? null : 'Brouillon enregistré.') && $suivant) {
            $this->etape = 2;
        }
    }

    /** Dès qu'un fichier est choisi pour une pièce, il est contrôlé puis conservé. */
    public function updatedFichiers(mixed $fichier, string $cle): void
    {
        $type = TypePiece::find((int) $cle);
        $demande = $this->demandeExigee();

        if ($type === null || $fichier === null) {
            return;
        }

        $this->executer(fn () => app(DossierStagiaire::class)->deposerPiece($demande, $type, $fichier), "{$type->libelle} : fichier déposé.");

        unset($this->fichiers[$cle]);
    }

    public function retirerPiece(DossierStagiaire $dossiers, int $piece): void
    {
        $demande = $this->demandeExigee();
        $ligne = PieceJointe::where('demande_id', $demande->id)->findOrFail($piece);

        $this->executer(fn () => $dossiers->retirerPiece($demande, $ligne), 'Pièce retirée.');
    }

    public function voirPiece(int $piece): void
    {
        abort_unless(PieceJointe::where('demande_id', $this->demandeExigee()->id)->whereKey($piece)->exists(), 404);

        $this->apercu = $piece;
        $this->ouvrirDialogue('piece');
    }

    public function fermerDialogue(): void
    {
        $this->apercu = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function soumettre(DossierStagiaire $dossiers): void
    {
        $this->executer(fn () => $dossiers->soumettre($this->demandeExigee()), 'Dossier déposé. Votre accusé de réception est disponible.');
    }

    public function confirmer(ConfirmationAdmission $confirmation): void
    {
        $this->executer(fn () => $confirmation->confirmer($this->demandeExigee()), 'Place confirmée. L’autorisation de paiement vous sera délivrée par le chef de pôle.');
    }

    public function desister(ConfirmationAdmission $confirmation): void
    {
        $valides = $this->validate([
            'raison' => ['required', Rule::enum(RaisonDesistement::class)],
            'explication' => ['required', 'string', 'min:10', 'max:1000'],
        ], attributes: ['raison' => 'raison', 'explication' => 'explication']);

        if ($this->executer(fn () => $confirmation->desister($this->demandeExigee(), RaisonDesistement::from($valides['raison']), $valides['explication']), 'Votre désistement est enregistré.')) {
            $this->fermerDialogue();
            $this->reset('explication');
        }
    }

    public function render()
    {
        $dossiers = app(DossierStagiaire::class);
        $stagiaire = $this->stagiaire()->loadMissing('etablissement');
        $demande = $this->demande();

        if ($demande === null) {
            return view('livewire.stagiaire.dossier', [
                'stagiaire' => $stagiaire,
                'demande' => null,
                'campagneOuverte' => $dossiers->campagneOuverte($stagiaire)?->load('internat'),
            ]);
        }

        $demande->load('campagne.internat.etablissement', 'desistement');
        $pieces = $dossiers->piecesAttendues($demande);
        $requises = $pieces->filter(fn (array $p) => $p['requise']);
        $manquantes = $requises->filter(fn (array $p) => $p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer);

        return view('livewire.stagiaire.dossier', [
            'stagiaire' => $stagiaire,
            'demande' => $demande,
            'modifiable' => $demande->estModifiableParLeStagiaire(),
            'pieces' => $pieces,
            'requises' => $requises,
            'manquantes' => $manquantes,
            'enregistre' => trim((string) $demande->adresse_complete) !== '',
            'pieceAffichee' => $this->apercu !== null ? $pieces->pluck('piece')->filter()->firstWhere('id', $this->apercu) : null,
            'historique' => HistoriqueDemande::where('demande_id', $demande->id)->orderBy('date_heure')->orderBy('id')->get(),
            'regles' => fn (TypePiece $type) => app(Documents::class)->regles($type->formats_acceptes),
        ]);
    }
}
