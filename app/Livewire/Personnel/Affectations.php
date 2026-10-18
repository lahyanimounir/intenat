<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\AffectationLits;
use App\Enums\StatutDemande;
use App\Enums\StatutPaiement;
use App\Livewire\Concerns\AvecChoixDeLit;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Demande;
use App\Models\Lit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Candidats à loger : admis ayant confirmé leur place et sans lit. Pour chacun,
 * le paiement de l'hébergement et la permission d'occuper sont montrés côte à
 * côte ; la permission est une décision propre du gestionnaire, distincte du
 * paiement.
 */
#[Title('Affectations')]
class Affectations extends PagePersonnel
{
    use AvecChoixDeLit, AvecTableau;

    #[Url(except: '')]
    public string $internat = '';

    #[Locked]
    public ?int $demandeId = null;

    public string $lit = '';

    public string $date_debut = '';

    public string $motif = '';

    public function mount(): void
    {
        $this->exiger('hebergement.consulter');
    }

    protected function filtres(): array
    {
        return ['internat'];
    }

    /** La demande est recherchée dans le périmètre : un identifiant étranger n'existe pas pour ce compte. */
    private function demande(int $id): Demande
    {
        $demande = Demande::visiblesPar($this->utilisateur())->findOrFail($id);
        $this->autoriser($demande, 'hebergement.gerer');

        return $demande;
    }

    public function autoriserOccupation(AffectationLits $affectations, int $demande): void
    {
        $ligne = $this->demande($demande);

        $this->executer(fn () => $affectations->autoriserOccupation($ligne, $this->utilisateur()), 'Occupation autorisée : un lit peut être attribué.');
    }

    public function preparer(int $demande): void
    {
        $this->demandeId = $this->demande($demande)->id;
        $this->lit = '';
        $this->date_debut = now()->toDateString();
        $this->motif = '';
        $this->ouvrirDialogue('affectation');
    }

    public function affecter(AffectationLits $affectations): void
    {
        $demande = $this->demande((int) $this->demandeId);

        $valides = $this->validate([
            'lit' => ['required', 'integer'],
            'date_debut' => ['required', 'date', 'after_or_equal:today'],
            'motif' => ['nullable', 'string', 'max:500'],
        ], ['lit.required' => 'Choisissez un lit.', 'date_debut.after_or_equal' => 'La date d\'entrée ne peut pas être passée.'],
            ['lit' => 'lit', 'date_debut' => 'date d\'entrée', 'motif' => 'motif']);

        $lit = Lit::visiblesPar($this->utilisateur())->findOrFail((int) $valides['lit']);

        if ($this->executer(
            fn () => $affectations->affecter($demande, $lit, Carbon::parse($valides['date_debut']), $this->utilisateur(), $valides['motif'] ?: null),
            'Lit attribué. Le stagiaire est prévenu.',
        )) {
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->demandeId = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger('hebergement.consulter');
        $utilisateur = $this->utilisateur();
        $affectations = app(AffectationLits::class);
        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';

        $candidats = $this->paginer(
            Demande::visiblesPar($utilisateur)
                ->where('statut', StatutDemande::Confirme->value)
                ->whereDoesntHave('affectationActive')
                ->with('stagiaire:id,nom,prenom,cef,nom_filiere', 'campagne.internat:id,nom', 'paiements')
                ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
                ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('numero_dossier', 'like', $motif)
                    ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif))))
                ->orderBy('date_confirmation')
        );

        $candidats->getCollection()->each(function (Demande $d) use ($affectations) {
            $d->dernierPaiement = $d->paiements->first();
            $d->recuValide = $d->paiements->contains(fn ($p) => $p->statut === StatutPaiement::Valide);
            $d->obstacles = $d->occupationAutorisee() ? [] : $affectations->obstaclesOccupation($d);
        });

        $aLoger = $this->dialogue === 'affectation' && $this->demandeId !== null
            ? Demande::with('stagiaire:id,nom,prenom', 'campagne:id,internat_id')->find($this->demandeId) : null;

        return view('livewire.personnel.affectations', [
            'candidats' => $candidats,
            'internats' => $this->internatsDuPerimetre(),
            'gerer' => $utilisateur->can('hebergement.gerer'),
            'aLoger' => $aLoger,
            'lits' => $aLoger ? $this->litsAffectables($aLoger->campagne->internat_id) : collect(),
        ]);
    }
}
