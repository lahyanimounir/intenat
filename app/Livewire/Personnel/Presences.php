<?php

namespace App\Livewire\Personnel;

use App\Domain\Residence\TenueInternat;
use App\Domain\Residence\VieInternat;
use App\Enums\StatutSortie;
use App\Livewire\PagePersonnel;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\DemandeSortie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Sorties et absences d'un internat : décision sur les demandes de sortie,
 * feuille d'appel du jour, registre des absences. Une absence couverte par une
 * sortie acceptée est « autorisée » ; les autres déclenchent une alerte.
 */
#[Title('Sorties et absences')]
class Presences extends PagePersonnel
{
    use WithPagination;

    private const PERMISSION = 'vie_internat.gerer';

    private const ONGLETS = ['sorties', 'feuille', 'absences'];

    #[Url(except: 'sorties')]
    public string $onglet = 'sorties';

    #[Url(except: '')]
    public string $internat = '';

    #[Url(except: '')]
    public string $statut = '';

    /** Jour de la feuille d'appel. */
    #[Url(except: '')]
    public string $jour = '';

    /** Résidents cochés absents sur la feuille. */
    public array $absents = [];

    /** Registre : absences non autorisées seulement. */
    public bool $nonAutorisees = false;

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);

        if (! in_array($this->onglet, self::ONGLETS, true)) {
            $this->onglet = 'sorties';
        }
    }

    public function voir(string $onglet): void
    {
        $this->onglet = in_array($onglet, self::ONGLETS, true) ? $onglet : 'sorties';
        $this->reset('absents');
        $this->resetPage();
    }

    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['internat', 'statut', 'jour', 'nonAutorisees'], true)) {
            $this->reset('absents');
            $this->resetPage();
        }
    }

    private function date(): Carbon
    {
        $date = rescue(fn () => Carbon::parse($this->jour ?: now())->startOfDay(), now()->startOfDay(), false);

        // Une absence ne se constate pas à l'avance.
        return $date->isFuture() ? now()->startOfDay() : $date;
    }

    private function sortie(int $id): DemandeSortie
    {
        $sortie = DemandeSortie::findOrFail($id);
        $this->autoriser($sortie, self::PERMISSION);

        return $sortie;
    }

    public function accepter(VieInternat $vie, int $sortie): void
    {
        $ligne = $this->sortie($sortie);

        $this->executer(fn () => $vie->traiterSortie($ligne, true, null, $this->utilisateur()), 'Sortie acceptée. Le résident est prévenu.');
    }

    public function refuser(VieInternat $vie, int $sortie, ?string $motif = null): void
    {
        $ligne = $this->sortie($sortie);
        $motif = $this->motifExige($motif, 5, 500, 'Indiquez le motif du refus (5 caractères au moins).');

        $this->executer(fn () => $vie->traiterSortie($ligne, false, $motif, $this->utilisateur()), 'Sortie refusée. Le motif est communiqué au résident.');
    }

    public function saisir(VieInternat $vie): void
    {
        $this->exiger(self::PERMISSION);
        $internat = $this->internatChoisi($this->internat);
        abort_if($internat === null, 404);

        $ids = collect($this->absents)->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            $this->message('Cochez au moins un résident absent.', 'danger');

            return;
        }

        $this->executer(function () use ($vie, $internat, $ids) {
            $bilan = $vie->saisirAbsences($internat->id, $this->date(), $ids, $this->utilisateur());
            $this->message($bilan['creees'].' absence(s) enregistrée(s)'.($bilan['non_autorisees'] > 0 ? ', dont '.$bilan['non_autorisees'].' non autorisée(s) : une alerte est émise.' : '.'));
            $this->reset('absents');
        });
    }

    public function annulerAbsence(TenueInternat $tenue, int $absence): void
    {
        $ligne = Absence::findOrFail($absence);
        $this->autoriser($ligne, self::PERMISSION);

        $this->executer(fn () => $tenue->annulerAbsence($ligne), 'Absence annulée.');
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $utilisateur = $this->utilisateur();
        $internat = $this->internatChoisi($this->internat);
        $date = $this->date();
        $donnees = [];

        if ($this->onglet === 'sorties') {
            $donnees['sorties'] = DemandeSortie::visiblesPar($utilisateur)
                ->with('affectation.demande.stagiaire:id,nom,prenom,cef', 'affectation.lit.chambre', 'traitant:id,nom,prenom')
                ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('affectation.demande.campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
                ->when($this->statut !== '', fn (Builder $q) => $q->where('statut', $this->statut))
                ->orderByRaw('statut = ? DESC', [StatutSortie::EnAttente->value])->orderByDesc('date_depart')
                ->paginate(25);
        } elseif ($this->onglet === 'feuille' && $internat !== null) {
            $donnees['residents'] = Affectation::query()
                ->whereDate('date_debut', '<=', $date)
                ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
                ->whereHas('demande.campagne', fn (Builder $q) => $q->where('internat_id', $internat->id))
                ->with('demande.stagiaire:id,nom,prenom,cef', 'lit.chambre')
                ->with(['absences' => fn ($q) => $q->whereDate('date_absence', $date)])
                ->with(['sorties' => fn ($q) => $q->where('statut', StatutSortie::Acceptee->value)->whereDate('date_depart', '<=', $date)->whereDate('date_retour', '>=', $date)])
                ->get()
                ->sortBy(fn (Affectation $a) => [$a->lit->chambre->numero, $a->lit->numero])->values();
        } elseif ($this->onglet === 'absences') {
            $donnees['absences'] = Absence::visiblesPar($utilisateur)
                ->with('affectation.demande.stagiaire:id,nom,prenom,cef', 'affectation.lit.chambre', 'auteur:id,nom,prenom')
                ->when($this->internat !== '', fn (Builder $q) => $q->whereHas('affectation.demande.campagne', fn (Builder $c) => $c->where('internat_id', (int) $this->internat)))
                ->when($this->nonAutorisees, fn (Builder $q) => $q->where('autorisee', false))
                ->orderByDesc('date_absence')->orderByDesc('id')
                ->paginate(25);
        }

        return view('livewire.personnel.presences', $donnees + [
            'choisi' => $internat,
            'internats' => $this->internatsDuPerimetre(),
            'date' => $date,
            'enAttente' => DemandeSortie::visiblesPar($utilisateur)->where('statut', StatutSortie::EnAttente->value)->count(),
            'statuts' => StatutSortie::options(),
        ]);
    }
}
