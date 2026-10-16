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
 * Suivi des frais d'internat, période par période. Trois étapes distinctes :
 * l'autorisation de paiement (chef de pôle), le dépôt du reçu (stagiaire), son
 * contrôle (gestionnaire). Aucun paiement en ligne.
 *
 * Ces frais d'hébergement sont un processus à part : ils ne se confondent pas
 * avec le règlement des frais d'inscription en formation, qui relève de
 * l'établissement et n'est ni saisi ni contrôlé ici.
 */
#[Title('Suivi des paiements')]
class Paiements extends PagePersonnel
{
    use AvecTableau;

    private const CONSULTER = 'paiements.consulter';

    private const AUTORISER = 'paiements.autoriser';

    private const CONTROLER = 'paiements.controler';

    /** Un ou plusieurs statuts séparés par une virgule (liens du tableau de bord). */
    #[Url(except: '')]
    public string $statut = '';

    #[Url(except: '')]
    public string $periode = '';

    #[Url(except: '')]
    public string $internat = '';

    /** Paiements cochés pour une autorisation groupée. */
    public array $selection = [];

    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, string> */
    public array $autorisation = ['periode' => '', 'montant' => ''];

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
            $this->reset('selection');
            $this->resetPage();
        }
    }

    public function effacerFiltres(): void
    {
        $this->reset('recherche', 'statut', 'periode', 'internat', 'selection');
        $this->resetPage();
    }

    private function paiement(int $id, string $permission): Paiement
    {
        $paiement = Paiement::findOrFail($id);
        $this->autoriser($paiement, $permission);

        return $paiement;
    }

    /* --------------------------------------------- Autorisation (chef de pôle) */

    public function preparerAutorisation(int $id): void
    {
        $paiement = $this->paiement($id, self::AUTORISER);
        $this->ligne = $paiement->id;
        $this->autorisation = ['periode' => $paiement->periode, 'montant' => (string) $paiement->montant];
        $this->ouvrirDialogue('autorisation');
    }

    public function delivrer(SuiviPaiements $suivi): void
    {
        $paiement = $this->paiement((int) $this->ligne, self::AUTORISER);

        $valides = $this->validate([
            'autorisation.periode' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'autorisation.montant' => ['required', 'numeric', 'min:0', 'max:99999'],
        ], ['autorisation.periode.regex' => 'Période attendue au format AAAA-MM.'], [
            'autorisation.periode' => 'période', 'autorisation.montant' => 'montant',
        ])['autorisation'];

        if ($this->executer(fn () => $suivi->autoriser($paiement, $this->utilisateur(), $valides['periode'], (float) $valides['montant']),
            'Autorisation de paiement délivrée : le stagiaire est notifié.')) {
            $this->fermerDialogue();
        }
    }

    /** Autorisation groupée : seules les lignes du périmètre et encore en attente sont traitées. */
    public function autoriserSelection(SuiviPaiements $suivi): void
    {
        $this->exiger(self::AUTORISER);
        $ids = collect($this->selection)->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->unique()->take(200)->values();

        if ($ids->isEmpty()) {
            $this->message('Cochez au moins un paiement en attente d\'autorisation.', 'danger');

            return;
        }

        $paiements = Paiement::visiblesPar($this->utilisateur())->whereIn('paiement.id', $ids)->where('paiement.statut', StatutPaiement::EnAttente->value)->get();

        $this->executer(function () use ($suivi, $paiements, $ids) {
            foreach ($paiements as $paiement) {
                $suivi->autoriser($paiement, $this->utilisateur());
            }

            $ignores = $ids->count() - $paiements->count();
            $this->message($paiements->count().' autorisation(s) délivrée(s)'.($ignores > 0 ? ', '.$ignores.' ligne(s) ignorée(s).' : '.'));
        });

        $this->reset('selection');
    }

    /* ------------------------------------------ Contrôle du reçu (gestionnaire) */

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
            ->when($periode !== '', fn (Builder $q) => $q->where('periode', $periode))
            ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('demande.campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
            ->when($terme !== '', fn (Builder $q) => $q->whereHas('demande', fn (Builder $d) => $d->where(fn (Builder $s) => $s
                ->where('numero_dossier', 'like', $motif)
                ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif)))))
            ->orderByDesc('periode')->orderByDesc('paiement.id')
            ->paginate(25);

        return view('livewire.personnel.paiements', [
            'paiements' => $paiements,
            'internats' => $this->internatsDuPerimetre(),
            'peutAutoriser' => $this->peut(self::AUTORISER),
            'peutControler' => $this->peut(self::CONTROLER),
            'filtresActifs' => $terme !== '' || $this->statut !== '' || $this->periode !== '' || $this->internat !== '',
            'choisi' => $this->ligne !== null ? Paiement::with('demande.stagiaire:id,nom,prenom')->find($this->ligne) : null,
        ]);
    }
}
