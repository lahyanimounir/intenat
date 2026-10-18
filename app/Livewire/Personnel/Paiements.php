<?php

namespace App\Livewire\Personnel;

use App\Domain\Payments\SuiviPaiements;
use App\Enums\StatutPaiement;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Paiement;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Suivi des frais d'internat, mois par mois. Le stagiaire paie directement et dépose son
 * justificatif ; le gestionnaire le vérifie ; le chef de pôle consulte et suit, sans rien autoriser.
 * Ces frais d'hébergement ne se confondent pas avec les frais d'inscription en formation,
 * qui relèvent de l'établissement.
 */
#[Title('Suivi des paiements')]
class Paiements extends PagePersonnel
{
    use AvecTableau;

    private const CONSULTER = 'paiements.consulter';

    private const CONTROLER = 'paiements.controler';

    /** Un ou plusieurs statuts séparés par une virgule (liens du tableau de bord). */
    #[Url(except: '')]
    public string $statut = '';

    #[Url(except: '')]
    public string $internat = '';

    /** Mois suivi, au format AAAA-MM. */
    #[Url(except: '')]
    public string $periode = '';

    #[Locked]
    public ?int $ligne = null;

    public function mount(): void
    {
        $this->exiger(self::CONSULTER);
    }

    protected function filtres(): array
    {
        return ['statut', 'periode', 'internat'];
    }

    public function updated(string $propriete): void
    {
        if ($propriete === 'recherche' || in_array($propriete, $this->filtres(), true)) {
            $this->resetPage();
        }
    }

    public function effacerFiltres(): void
    {
        $this->reset('recherche', 'statut', 'periode', 'internat');
        $this->resetPage();
    }

    private function paiement(int $id, string $permission): Paiement
    {
        $paiement = Paiement::findOrFail($id);
        $this->autoriser($paiement, $permission);

        return $paiement;
    }

    public function valider(SuiviPaiements $suivi, int $id): void
    {
        $paiement = $this->paiement($id, self::CONTROLER);

        $this->executer(fn () => $suivi->validerRecu($paiement, $this->utilisateur()), 'Reçu validé.');
    }

    public function refuser(SuiviPaiements $suivi, int $id, ?string $motif = null): void
    {
        $paiement = $this->paiement($id, self::CONTROLER);
        $motif = $this->motifExige($motif, 5, 500, 'Indiquez le motif du refus (5 caractères au moins).');

        $this->executer(fn () => $suivi->refuserRecu($paiement, $this->utilisateur(), $motif), 'Reçu refusé : le stagiaire est invité à en déposer un nouveau.');
    }

    public function voirRecu(int $id): void
    {
        $paiement = $this->paiement($id, self::CONSULTER);
        abort_if($paiement->recu_fichier === null, 404);
        $this->ligne = $paiement->id;
        $this->ouvrirDialogue('recu');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::CONSULTER);

        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';
        $statuts = array_values(array_intersect(explode(',', $this->statut), StatutPaiement::valeurs()));
        $periode = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->periode) === 1 ? $this->periode : '';

        $paiements = Paiement::visiblesPar($this->utilisateur())
            ->with('demande.stagiaire:id,nom,prenom,cef', 'demande.campagne.internat:id,nom')
            ->when($statuts !== [], fn (Builder $q) => $q->whereIn('paiement.statut', $statuts))
            ->when($periode !== '', fn (Builder $q) => $q->where('paiement.periode', $periode))
            ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('demande.campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
            ->when($terme !== '', fn (Builder $q) => $q->whereHas('demande', fn (Builder $d) => $d->where(fn (Builder $s) => $s
                ->where('numero_dossier', 'like', $motif)
                ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif)))))
            ->orderByDesc('paiement.periode')->orderByDesc('paiement.id')
            ->paginate(25);

        return view('livewire.personnel.paiements', [
            'paiements' => $paiements,
            'internats' => $this->internatsDuPerimetre(),
            'peutControler' => $this->peut(self::CONTROLER),
            'filtresActifs' => $terme !== '' || $this->statut !== '' || $this->periode !== '' || $this->internat !== '',
            'choisi' => $this->ligne !== null ? Paiement::with('demande.stagiaire:id,nom,prenom')->find($this->ligne) : null,
        ]);
    }
}
