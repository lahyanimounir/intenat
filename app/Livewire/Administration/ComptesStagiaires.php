<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\ComptesStagiaires as Comptes;
use App\Enums\EtatCompte;
use App\Enums\ResultatVerification;
use App\Enums\StatutInscription;
use App\Livewire\Concerns\AvecHierarchie;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Comptes des stagiaires. Un compte est créé par le stagiaire lui-même ;
 * l'administrateur ne fixe ni ne voit aucun mot de passe. Il suspend, rétablit,
 * ou renvoie un lien de réinitialisation.
 *
 * Le second onglet est une archive : les premières connexions par MyWay qui
 * n'avaient pas abouti, du temps où l'identité venait de ce service. Elle se
 * consulte, sans plus rien y décider.
 */
#[Title('Comptes stagiaires')]
class ComptesStagiaires extends PagePersonnel
{
    use AvecHierarchie, AvecTableau;

    private const PERMISSION = 'stagiaires.gerer';

    #[Url(except: 'comptes')]
    public string $onglet = 'comptes';

    #[Url(except: '')]
    public string $etat = '';

    #[Url(except: '')]
    public string $inscription = '';

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
        $this->onglet = $this->onglet === 'archive' ? 'archive' : 'comptes';
    }

    protected function filtres(): array
    {
        return [...$this->filtresHierarchiques(), 'etat', 'inscription'];
    }

    public function effacerFiltres(): void
    {
        $this->reset('recherche', 'region', 'province', 'complexe', 'etablissement', 'etat', 'inscription');
        $this->resetPage();
    }

    public function voir(string $onglet): void
    {
        $this->onglet = $onglet === 'archive' ? 'archive' : 'comptes';
        $this->resetPage();
    }

    private function stagiaire(int $id): Stagiaire
    {
        $this->exiger(self::PERMISSION);

        return Stagiaire::findOrFail($id);
    }

    public function suspendre(Comptes $comptes, int $id, ?string $motif = null): void
    {
        $stagiaire = $this->stagiaire($id);
        $motif = $this->motifExige($motif, 5, 255, 'Indiquez le motif de la suspension (5 caractères au moins).');

        $this->executer(fn () => $comptes->suspendre($stagiaire, $motif), 'Compte suspendu.');
    }

    public function retablir(Comptes $comptes, int $id): void
    {
        $stagiaire = $this->stagiaire($id);

        $this->executer(fn () => $comptes->retablir($stagiaire), 'Compte rétabli.');
    }

    public function envoyerLien(Comptes $comptes, int $id): void
    {
        $stagiaire = $this->stagiaire($id);

        $this->executer(fn () => $comptes->envoyerReinitialisation($stagiaire), 'Lien de réinitialisation envoyé à l’adresse du compte.');
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $donnees = ['archives' => VerificationIdentite::where('resultat', '!=', ResultatVerification::Verifiee->value)->count()];

        if ($this->onglet === 'archive') {
            $donnees['verifications'] = VerificationIdentite::query()->with('examinateur:id,nom,prenom')
                ->where('resultat', '!=', ResultatVerification::Verifiee->value)
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(25);
        } else {
            $terme = trim($this->recherche);
            $debut = addcslashes($terme, '%_\\').'%';

            $donnees['filtre'] = $this->listesDesFiltres();

            // Un stagiaire relève de la hiérarchie par l'établissement qu'il a déclaré.
            $requete = Stagiaire::query()->with('etablissement:id,code_efp,nom_efp')
                ->when($this->etablissementsFiltres(), fn (Builder $q, Builder $etablissements) => $q->whereIn('stagiaire.etablissement_id', $etablissements))
                ->when(EtatCompte::tryFrom($this->etat), fn (Builder $q, EtatCompte $etat) => $q->where('etat_compte', $etat->value))
                ->when(StatutInscription::tryFrom($this->inscription), fn (Builder $q, StatutInscription $statut) => $q->where('statut_inscription', $statut->value))
                ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                    ->where('cef', 'like', $debut)->orWhere('email', 'like', '%'.$debut)->orWhere('nom', 'like', $debut)->orWhere('prenom', 'like', $debut)));

            $this->appliquerTri($requete, ['nom' => 'nom', 'cef' => 'cef', 'cree_le' => 'inscrit_le'], 'nom')->orderBy('id');

            $donnees['comptes'] = $this->paginer($requete);
            $donnees['filtresActifs'] = $terme !== '' || $this->etat !== '' || $this->inscription !== '' || $this->hierarchieFiltree();
        }

        return view('livewire.administration.comptes-stagiaires', $donnees);
    }
}
