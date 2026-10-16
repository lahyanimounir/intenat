<?php

namespace App\Livewire\Personnel;

use App\Enums\StatutDemande;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Demande;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Dossiers de préinscription déposés, dans le périmètre du compte. Un brouillon reste privé au stagiaire. */
#[Title('Dossiers de préinscription')]
class Dossiers extends PagePersonnel
{
    use AvecTableau;

    #[Url(except: '')]
    public string $statut = '';

    #[Url(except: '')]
    public string $campagne = '';

    #[Url(except: '')]
    public string $internat = '';

    /** `attendu` : candidats admis dont le dossier papier n'a pas été reçu (lien du tableau de bord). */
    #[Url(as: 'dossier_physique', except: '')]
    public string $dossierPhysique = '';

    public function mount(): void
    {
        $this->exiger('demandes.consulter');
    }

    protected function filtres(): array
    {
        return ['statut', 'campagne', 'internat', 'dossierPhysique'];
    }

    public function effacerFiltres(): void
    {
        $this->reset('recherche', 'statut', 'campagne', 'internat', 'dossierPhysique');
        $this->resetPage();
    }

    public function render()
    {
        $this->exiger('demandes.consulter');

        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';
        $statuts = array_values(array_intersect(explode(',', $this->statut), StatutDemande::valeurs()));

        $requete = Demande::visiblesPar($this->utilisateur())
            ->where('demande.statut', '!=', StatutDemande::Brouillon->value)
            ->with('stagiaire:id,nom,prenom,cef,nom_filiere,statut_inscription', 'campagne.internat:id,nom')
            ->when($statuts !== [], fn (Builder $q) => $q->whereIn('demande.statut', $statuts))
            ->when($this->campagne !== '', fn (Builder $q) => $q->where('campagne_id', (int) $this->campagne))
            ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
            ->when($this->dossierPhysique === 'attendu', fn (Builder $q) => $q->where('dossier_physique_depose', false))
            ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('numero_dossier', 'like', $motif)
                ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif))));

        $this->appliquerTri($requete, ['date_soumission' => 'date_soumission', 'numero_dossier' => 'numero_dossier', 'statut' => 'demande.statut'], 'date_soumission', 'desc')
            ->orderByDesc('demande.id');

        $internats = $this->internatsDuPerimetre();

        return view('livewire.personnel.dossiers', [
            'dossiers' => $this->paginer($requete),
            'statuts' => collect(StatutDemande::options())->reject(fn (array $o) => $o['valeur'] === StatutDemande::Brouillon->value)->values()->all(),
            'campagnes' => $this->campagnesDuPerimetre(),
            'internats' => $internats,
            'filtresActifs' => $terme !== '' || $this->statut !== '' || $this->campagne !== '' || $this->internat !== '' || $this->dossierPhysique !== '',
        ]);
    }
}
