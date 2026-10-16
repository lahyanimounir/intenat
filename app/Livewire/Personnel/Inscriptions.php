<?php

namespace App\Livewire\Personnel;

use App\Domain\Identity\ControleInscription;
use App\Enums\StatutInscription;
use App\Enums\StatutVerificationInscription;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Etablissement;
use App\Models\Stagiaire;
use App\Models\VerificationInscription;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Vérification des inscriptions en formation déclarées par les stagiaires.
 *
 * Trois vues : les demandes en attente, les inscriptions reprises de l'ancien
 * parcours MyWay qu'aucun agent n'a encore confirmées, et l'ensemble des
 * stagiaires du périmètre avec l'état de leur inscription. L'agent ne voit que
 * les stagiaires de son périmètre — pour un gestionnaire, ceux du genre que son
 * internat accueille.
 */
#[Title('Inscriptions à vérifier')]
class Inscriptions extends PagePersonnel
{
    use AvecTableau;

    private const VUES = ['attente', 'reprises', 'stagiaires'];

    #[Url(as: 'vue', except: 'attente')]
    public string $vue = 'attente';

    #[Url(except: '')]
    public string $statut = '';

    #[Url(as: 'efp', except: '')]
    public string $etablissement = '';

    public function mount(): void
    {
        $this->exiger(ControleInscription::PERMISSION);

        if (! in_array($this->vue, self::VUES, true)) {
            $this->vue = 'attente';
        }
    }

    public function voir(string $vue): void
    {
        $this->vue = in_array($vue, self::VUES, true) ? $vue : 'attente';
        $this->reset('statut', 'tri', 'sens');
        $this->resetPage();
    }

    protected function filtres(): array
    {
        return ['statut', 'etablissement'];
    }

    /** Stagiaires du périmètre, filtrés par la recherche et l'établissement. */
    private function stagiaires(): Builder
    {
        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';

        return $this->perimetre()->restreindreStagiaires(Stagiaire::query(), $this->utilisateur())
            ->when($this->etablissement !== '', fn (Builder $q) => $q->where('stagiaire.etablissement_id', (int) $this->etablissement))
            ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('stagiaire.nom', 'like', $motif)
                ->orWhere('stagiaire.prenom', 'like', $motif)
                ->orWhere('stagiaire.cef', 'like', addcslashes($terme, '%_\\').'%')
                ->orWhere('stagiaire.email', 'like', $motif)));
    }

    private function verifications(): Builder
    {
        return VerificationInscription::query()->whereIn('stagiaire_id', $this->stagiaires()->select('stagiaire.id'));
    }

    public function render()
    {
        $this->exiger(ControleInscription::PERMISSION);

        $enAttente = $this->verifications()->enAttente();
        $reprises = $this->verifications()->reprisesAReexaminer()
            ->whereHas('stagiaire', fn (Builder $q) => $q->where('statut_inscription', StatutInscription::Verifiee->value))
            // Une reprise n'est plus à réexaminer dès qu'un agent a rendu une décision plus récente.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('verification_inscription as suivante')
                ->whereColumn('suivante.stagiaire_id', 'verification_inscription.stagiaire_id')
                ->whereColumn('suivante.id', '>', 'verification_inscription.id')
                ->whereNotNull('suivante.decidee_par'));

        $lignes = match ($this->vue) {
            'attente' => $this->paginer((clone $enAttente)
                ->with('stagiaire:id,nom,prenom,cef,genre,email', 'etablissement:id,nom_efp,code_efp')
                ->orderBy('soumise_le')),
            'reprises' => $this->paginer((clone $reprises)
                ->with('stagiaire:id,nom,prenom,cef,genre,email', 'etablissement:id,nom_efp,code_efp')
                ->orderBy('id')),
            default => $this->paginer($this->appliquerTri(
                $this->stagiaires()
                    ->where('stagiaire.statut_inscription', '!=', StatutInscription::NonDeclaree->value)
                    ->when($this->statut !== '', fn (Builder $q) => $q->where('stagiaire.statut_inscription', $this->statut))
                    ->with('etablissement:id,nom_efp,code_efp'),
                ['nom' => 'stagiaire.nom', 'cef' => 'stagiaire.cef', 'statut' => 'stagiaire.statut_inscription', 'verifiee' => 'stagiaire.inscription_verifiee_le'],
                'nom',
            )),
        };

        $etablissementIds = $this->perimetre()->etablissementIds($this->utilisateur());

        return view('livewire.personnel.inscriptions', [
            'lignes' => $lignes,
            'compteurs' => ['attente' => (clone $enAttente)->count(), 'reprises' => (clone $reprises)->count()],
            'etablissements' => Etablissement::query()
                ->when($etablissementIds !== null, fn (Builder $q) => $q->whereIn('id', $etablissementIds))
                ->orderBy('nom_efp')->pluck('nom_efp', 'id'),
            'statuts' => collect(StatutInscription::options())->reject(fn (array $o) => $o['valeur'] === StatutInscription::NonDeclaree->value)->values()->all(),
            'decisions' => StatutVerificationInscription::class,
        ]);
    }
}
