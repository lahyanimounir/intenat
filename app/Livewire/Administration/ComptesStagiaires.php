<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\ComptesStagiaires as Comptes;
use App\Domain\Identity\Perimetre;
use App\Enums\EtatCompte;
use App\Enums\ResultatVerification;
use App\Enums\StatutInscription;
use App\Livewire\Concerns\AvecHierarchie;
use App\Livewire\Concerns\AvecReinitialisationMotDePasse;
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
 *
 * Le chef de pôle, le gestionnaire et le responsable restauration y accèdent pour la seule réinitialisation
 * du mot de passe des stagiaires de leur périmètre : ils ne voient que ces stagiaires.
 */
#[Title('Comptes stagiaires')]
class ComptesStagiaires extends PagePersonnel
{
    use AvecHierarchie, AvecReinitialisationMotDePasse, AvecTableau;

    private const PERMISSION = 'stagiaires.gerer';

    /** Réinitialiser le mot de passe des stagiaires de son périmètre. */
    private const REINITIALISER = 'mots_de_passe.stagiaires';

    #[Url(except: 'comptes')]
    public string $onglet = 'comptes';

    #[Url(except: '')]
    public string $etat = '';

    #[Url(except: '')]
    public string $inscription = '';

    public function mount(): void
    {
        $this->exiger(self::PERMISSION, self::REINITIALISER);
        $this->onglet = $this->onglet === 'archive' && $this->peut(self::PERMISSION) ? 'archive' : 'comptes';
    }

    protected function compteDeLaLigne(int $id): Stagiaire
    {
        $this->exiger(self::PERMISSION, self::REINITIALISER);

        return Stagiaire::findOrFail($id);
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
        $this->onglet = $onglet === 'archive' && $this->peut(self::PERMISSION) ? 'archive' : 'comptes';
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
        $this->exiger(self::PERMISSION, self::REINITIALISER);
        $gestion = $this->peut(self::PERMISSION);
        $donnees = [
            'gestion' => $gestion,
            'cibleMdp' => $this->compteEnReinitialisation(),
            'archives' => $gestion ? VerificationIdentite::where('resultat', '!=', ResultatVerification::Verifiee->value)->count() : 0,
        ];

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
                // Sans la gestion des comptes, la liste se limite aux stagiaires du périmètre du responsable.
                ->when(! $gestion, fn (Builder $q) => app(Perimetre::class)->restreindreStagiaires($q, $this->utilisateur()))
                ->when($this->etablissementsFiltres(), fn (Builder $q, Builder $etablissements) => $q->whereIn('stagiaire.etablissement_id', $etablissements))
                ->when(EtatCompte::tryFrom($this->etat), fn (Builder $q, EtatCompte $etat) => $q->where('etat_compte', $etat->value))
                ->when(StatutInscription::tryFrom($this->inscription), fn (Builder $q, StatutInscription $statut) => $q->where('statut_inscription', $statut->value))
                ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                    ->where('cef', 'like', $debut)->orWhere('email', 'like', '%'.$debut)->orWhere('nom', 'like', $debut)->orWhere('prenom', 'like', $debut)));

            $this->appliquerTri($requete, ['nom' => 'nom', 'cef' => 'cef', 'cree_le' => 'inscrit_le'], 'nom')->orderBy('id');

            $donnees['comptes'] = $this->paginer($requete);
            $donnees['reinitialisables'] = $this->reinitialisables($donnees['comptes']->getCollection());
            $donnees['filtresActifs'] = $terme !== '' || $this->etat !== '' || $this->inscription !== '' || $this->hierarchieFiltree();
        }

        return view('livewire.administration.comptes-stagiaires', $donnees);
    }
}
