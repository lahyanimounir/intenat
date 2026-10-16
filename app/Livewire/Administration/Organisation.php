<?php

namespace App\Livewire\Administration;

use App\Domain\Shared\StructureInstitutionnelle;
use App\Enums\Genre;
use App\Livewire\PagePersonnel;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Region;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Organisation : direction centrale → directions régionales → complexes / CMC
 * → établissements (EFP) → internats. La liste des établissements est le
 * référentiel dans lequel chaque stagiaire choisit le sien à l'inscription.
 */
#[Title('Organisation')]
class Organisation extends PagePersonnel
{
    private const PERMISSION = 'organisation.gerer';

    private const TITRES = ['regions' => 'une région', 'complexes' => 'un complexe / CMC', 'etablissements' => 'un établissement', 'internats' => 'un internat'];

    private const CHAMPS = [
        'regions' => ['code', 'nom'],
        'complexes' => ['region_id', 'code', 'nom'],
        'etablissements' => ['region_id', 'complexe_id', 'code_efp', 'nom_efp'],
        'internats' => ['etablissement_id', 'genre', 'nom'],
    ];

    /** Niveau de l'élément saisi : régions, complexes, établissements ou internats. */
    #[Locked]
    public string $niveau = '';

    /** Élément en cours de modification ; nul pour un ajout. */
    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, string> */
    public array $saisie = [];

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    private function modele(string $niveau, int $id): Model
    {
        $classe = StructureInstitutionnelle::NIVEAUX[$niveau] ?? abort(404);

        return $classe::findOrFail($id);
    }

    public function ajouter(string $niveau): void
    {
        $this->exiger(self::PERMISSION);
        abort_unless(isset(self::CHAMPS[$niveau]), 404);

        $this->niveau = $niveau;
        $this->ligne = null;
        $this->saisie = array_fill_keys(self::CHAMPS[$niveau], '');

        // Valeurs proposées : la première région, le premier établissement, les filles.
        match ($niveau) {
            'complexes', 'etablissements' => $this->saisie['region_id'] = (string) (Region::orderBy('nom')->value('id') ?? ''),
            'internats' => [$this->saisie['etablissement_id'], $this->saisie['genre']] = [(string) (Etablissement::orderBy('nom_efp')->value('id') ?? ''), Genre::Fille->value],
            default => null,
        };

        $this->ouvrirDialogue('element');
    }

    public function modifier(string $niveau, int $id): void
    {
        $this->exiger(self::PERMISSION);
        $modele = $this->modele($niveau, $id);

        $this->niveau = $niveau;
        $this->ligne = $id;
        $this->saisie = collect(self::CHAMPS[$niveau])
            ->mapWithKeys(fn (string $champ) => [$champ => (string) ($modele->{$champ} instanceof Genre ? $modele->{$champ}->value : $modele->{$champ})])->all();
        $this->ouvrirDialogue('element');
    }

    /** Changer de région vide le complexe choisi : il doit appartenir à la région. */
    public function updatedSaisieRegionId(): void
    {
        if (array_key_exists('complexe_id', $this->saisie)) {
            $this->saisie['complexe_id'] = '';
        }
    }

    public function enregistrer(StructureInstitutionnelle $structure): void
    {
        $this->exiger(self::PERMISSION);
        abort_unless(isset(self::CHAMPS[$this->niveau]), 404);
        $existant = $this->ligne !== null ? $this->modele($this->niveau, $this->ligne) : null;

        $saisie = collect($this->saisie)->only(self::CHAMPS[$this->niveau])->map(fn ($valeur) => trim((string) $valeur) === '' ? null : trim((string) $valeur))->all();
        $regles = collect(StructureInstitutionnelle::regles($this->niveau, $existant, $saisie))->mapWithKeys(fn ($regle, $champ) => ["saisie.{$champ}" => $regle])->all();
        $messages = collect(StructureInstitutionnelle::messages())->mapWithKeys(fn ($message, $cle) => ["saisie.{$cle}" => $message])->all();

        $this->saisie = array_map(fn ($valeur) => (string) $valeur, $saisie);
        $valides = validator(['saisie' => $saisie], $regles, $messages, [
            'saisie.region_id' => 'région', 'saisie.complexe_id' => 'complexe', 'saisie.etablissement_id' => 'établissement', 'saisie.genre' => 'genre',
            'saisie.code' => 'code', 'saisie.nom' => 'nom', 'saisie.code_efp' => 'code EFP', 'saisie.nom_efp' => 'nom EFP',
        ])->validate()['saisie'];

        if ($this->executer(fn () => $structure->enregistrer($this->niveau, $existant, $valides), 'Organisation mise à jour.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimer(StructureInstitutionnelle $structure, string $niveau, int $id): void
    {
        $this->exiger(self::PERMISSION);
        $modele = $this->modele($niveau, $id);

        if ($this->executer(fn () => $structure->supprimer($modele), 'Élément supprimé.')) {
            $this->perimetre()->oublier();
        }
    }

    public function fermerDialogue(): void
    {
        $this->niveau = '';
        $this->ligne = null;
        $this->saisie = [];
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);

        $regions = Region::orderBy('nom')->get();
        $complexes = Complexe::orderBy('nom')->get();
        $etablissements = Etablissement::orderBy('nom_efp')->get();

        return view('livewire.administration.organisation', [
            'regions' => $regions,
            'complexes' => $complexes->groupBy('region_id'),
            'nomsComplexes' => $complexes->pluck('nom', 'id'),
            'etablissements' => $etablissements->groupBy('region_id'),
            'internats' => Internat::orderBy('nom')->get()->groupBy('etablissement_id'),
            'titre' => ($this->ligne ? 'Modifier ' : 'Ajouter ').(self::TITRES[$this->niveau] ?? ''),
            // Listes de choix de la fenêtre.
            'choixRegions' => $regions->pluck('nom', 'id'),
            'choixComplexes' => $complexes->where('region_id', (int) ($this->saisie['region_id'] ?? 0))->pluck('nom', 'id'),
            'choixEtablissements' => $etablissements->pluck('nom_efp', 'id'),
        ]);
    }
}
