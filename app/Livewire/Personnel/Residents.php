<?php

namespace App\Livewire\Personnel;

use App\Enums\TypeMouvement;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Affectation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Résidents de l'internat : séjours en cours ou clos, avec leur lit et leur arrivée. */
#[Title('Résidents')]
class Residents extends PagePersonnel
{
    use AvecTableau;

    #[Url(except: '')]
    public string $internat = '';

    /** Vide : séjours en cours ; `clos` : séjours terminés. */
    #[Url(except: '')]
    public string $etat = '';

    /** `attendue` : lits attribués dont l'occupant n'est pas encore arrivé (lien du tableau de bord). */
    #[Url(except: '')]
    public string $arrivee = '';

    public function mount(): void
    {
        $this->exiger('hebergement.consulter');
    }

    protected function filtres(): array
    {
        return ['internat', 'etat', 'arrivee'];
    }

    public function render()
    {
        $this->exiger('hebergement.consulter');
        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';

        $requete = Affectation::visiblesPar($this->utilisateur())
            ->with('demande.stagiaire:id,nom,prenom,cef', 'demande.campagne.internat:id,nom', 'lit.chambre.batiment:id,code,nom', 'mouvements')
            ->when($this->etat === 'clos', fn (Builder $q) => $q->whereNotNull('date_fin'), fn (Builder $q) => $q->whereNull('date_fin'))
            ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('demande.campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
            ->when($this->arrivee === 'attendue', fn (Builder $q) => $q->whereDoesntHave('mouvements', fn (Builder $m) => $m->where('type', TypeMouvement::Arrivee->value)))
            ->when($terme !== '', fn (Builder $q) => $q->whereHas('demande', fn (Builder $d) => $d->where('numero_dossier', 'like', $motif)
                ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif))))
            ->orderByDesc('date_debut')->orderByDesc('id');

        return view('livewire.personnel.residents', [
            'residents' => $this->paginer($requete),
            'internats' => $this->internatsDuPerimetre(),
        ]);
    }
}
