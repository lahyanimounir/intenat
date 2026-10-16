<?php

namespace App\Livewire\Personnel;

use App\Domain\Residence\TenueInternat;
use App\Domain\Residence\VieInternat;
use App\Enums\StatutMaintenance;
use App\Enums\TypeIncident;
use App\Livewire\PagePersonnel;
use App\Models\Affectation;
use App\Models\Chambre;
use App\Models\DemandeMaintenance;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Incidents et maintenance d'un internat. Un incident concerne un résident ; une
 * demande de maintenance concerne une chambre et peut la rendre indisponible le
 * temps des travaux.
 */
#[Title('Incidents et maintenance')]
class Incidents extends PagePersonnel
{
    use WithFileUploads, WithPagination;

    private const PERMISSION = 'vie_internat.gerer';

    #[Url(except: 'incidents')]
    public string $onglet = 'incidents';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $statut = '';

    /** @var array<string, string> */
    public array $incident = ['affectation_id' => '', 'type' => '', 'description' => '', 'date_incident' => ''];

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $rapport = null;

    /** @var array<string, mixed> */
    public array $maintenance = ['chambre_id' => '', 'description' => '', 'bloque_chambre' => false];

    #[Locked]
    public ?int $ligne = null;

    public string $resolution = '';

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
        $this->onglet = $this->onglet === 'maintenance' ? 'maintenance' : 'incidents';
    }

    public function voir(string $onglet): void
    {
        $this->onglet = $onglet === 'maintenance' ? 'maintenance' : 'incidents';
        $this->resetPage();
    }

    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['type', 'statut'], true)) {
            $this->resetPage();
        }
    }

    /* ------------------------------------------------------------ Incidents */

    public function preparerIncident(): void
    {
        $this->exiger(self::PERMISSION);
        $this->incident = ['affectation_id' => '', 'type' => '', 'description' => '', 'date_incident' => now()->format('Y-m-d\TH:i')];
        $this->rapport = null;
        $this->ouvrirDialogue('incident');
    }

    public function enregistrerIncident(TenueInternat $tenue): void
    {
        $this->exiger(self::PERMISSION);

        $valides = $this->validate([
            'incident.affectation_id' => ['required', 'integer'],
            'incident.type' => ['required', Rule::enum(TypeIncident::class)],
            'incident.description' => ['required', 'string', 'min:10', 'max:2000'],
            'incident.date_incident' => ['required', 'date', 'before_or_equal:now'],
            'rapport' => ['nullable', 'file'],
        ], ['incident.affectation_id.required' => 'Choisissez le résident concerné.', 'incident.date_incident.before_or_equal' => 'Un incident ne se déclare pas à l\'avance.'], [
            'incident.affectation_id' => 'résident', 'incident.type' => 'type', 'incident.description' => 'description', 'incident.date_incident' => 'date', 'rapport' => 'rapport',
        ])['incident'];

        // Le résident est recherché dans le périmètre : un identifiant étranger n'existe pas pour ce compte.
        $affectation = Affectation::visiblesPar($this->utilisateur())->findOrFail((int) $valides['affectation_id']);
        $this->autoriser($affectation, self::PERMISSION);

        if ($this->executer(fn () => $tenue->enregistrerIncident(
            $affectation, TypeIncident::from($valides['type']), $valides['description'], Carbon::parse($valides['date_incident']), $this->rapport, $this->utilisateur(),
        ), 'Incident enregistré.')) {
            $this->fermerDialogue();
        }
    }

    /* ---------------------------------------------------------- Maintenance */

    public function preparerMaintenance(): void
    {
        $this->exiger(self::PERMISSION);
        $this->maintenance = ['chambre_id' => '', 'description' => '', 'bloque_chambre' => false];
        $this->ouvrirDialogue('maintenance');
    }

    public function signaler(VieInternat $vie): void
    {
        $this->exiger(self::PERMISSION);

        $valides = $this->validate([
            'maintenance.chambre_id' => ['required', 'integer'],
            'maintenance.description' => ['required', 'string', 'min:5', 'max:1000'],
            'maintenance.bloque_chambre' => ['boolean'],
        ], ['maintenance.chambre_id.required' => 'Choisissez la chambre concernée.'], [
            'maintenance.chambre_id' => 'chambre', 'maintenance.description' => 'description',
        ])['maintenance'];

        $chambre = Chambre::visiblesPar($this->utilisateur())->findOrFail((int) $valides['chambre_id']);
        $this->autoriser($chambre, self::PERMISSION);

        if ($this->executer(fn () => $vie->signalerMaintenance($chambre, $valides['description'], (bool) $valides['bloque_chambre'], $this->utilisateur()), 'Demande de maintenance signalée.')) {
            $this->fermerDialogue();
        }
    }

    private function demande(int $id): DemandeMaintenance
    {
        $demande = DemandeMaintenance::findOrFail($id);
        $this->autoriser($demande, self::PERMISSION);

        return $demande;
    }

    public function prendreEnCharge(VieInternat $vie, int $id): void
    {
        $demande = $this->demande($id);

        $this->executer(fn () => $vie->avancerMaintenance($demande, StatutMaintenance::EnCours, null, $this->utilisateur()), 'Demande prise en charge.');
    }

    public function preparerResolution(int $id): void
    {
        $this->ligne = $this->demande($id)->id;
        $this->resolution = '';
        $this->ouvrirDialogue('resolution');
    }

    public function resoudre(VieInternat $vie): void
    {
        $demande = $this->demande((int) $this->ligne);
        $resolution = $this->validate(['resolution' => ['required', 'string', 'min:3', 'max:1000']], attributes: ['resolution' => 'résolution'])['resolution'];

        if ($this->executer(fn () => $vie->avancerMaintenance($demande, StatutMaintenance::Resolue, $resolution, $this->utilisateur()), 'Demande résolue. La chambre est de nouveau disponible si elle était bloquée.')) {
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->rapport = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $utilisateur = $this->utilisateur();
        $donnees = [];

        if ($this->onglet === 'incidents') {
            $donnees['incidents'] = Incident::visiblesPar($utilisateur)
                ->with('affectation.demande.stagiaire:id,nom,prenom,cef', 'affectation.lit.chambre', 'auteur:id,nom,prenom')
                ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
                ->orderByDesc('date_incident')->paginate(25);
        } else {
            $donnees['maintenances'] = DemandeMaintenance::visiblesPar($utilisateur)
                ->with('chambre.batiment:id,code,nom', 'auteur:id,nom,prenom')
                ->when($this->statut !== '', fn (Builder $q) => $q->where('statut', $this->statut))
                ->orderByRaw('statut = ? ASC', [StatutMaintenance::Resolue->value])->orderByDesc('date_demande')->paginate(25);
        }

        return view('livewire.personnel.incidents', $donnees + [
            'aTraiter' => DemandeMaintenance::visiblesPar($utilisateur)->where('statut', '!=', StatutMaintenance::Resolue->value)->count(),
            // Listes de choix des fenêtres : résidents présents et chambres du périmètre.
            'residents' => $this->dialogue === 'incident'
                ? Affectation::visiblesPar($utilisateur)->whereNull('date_fin')->with('demande.stagiaire:id,nom,prenom', 'lit.chambre:id,numero')->get()
                    ->sortBy(fn (Affectation $a) => $a->demande->stagiaire->nom)
                    ->mapWithKeys(fn (Affectation $a) => [$a->id => $a->demande->stagiaire->nom.' '.$a->demande->stagiaire->prenom.' — chambre '.$a->lit->chambre->numero])
                : collect(),
            'chambres' => $this->dialogue === 'maintenance'
                ? Chambre::visiblesPar($utilisateur)->with('batiment:id,code,internat_id')->get()
                    ->sortBy(fn (Chambre $c) => [$c->batiment->code, $c->numero])
                    ->mapWithKeys(fn (Chambre $c) => [$c->id => 'Bâtiment '.$c->batiment->code.' — chambre '.$c->numero])
                : collect(),
        ]);
    }
}
