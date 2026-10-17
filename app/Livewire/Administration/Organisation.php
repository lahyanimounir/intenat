<?php

namespace App\Livewire\Administration;

use App\Domain\Shared\StructureInstitutionnelle;
use App\Enums\Genre;
use App\Livewire\PagePersonnel;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Province;
use App\Models\Region;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Organisation : direction centrale → directions régionales → provinces →
 * complexes / CMC → établissements (EFP) → internats. La hiérarchie est stricte
 * et descendante ; la liste des établissements est le référentiel dans lequel
 * chaque stagiaire choisit le sien à l'inscription.
 */
#[Title('Organisation')]
class Organisation extends PagePersonnel
{
    private const PERMISSION = 'organisation.gerer';

    private const TITRES = [
        'regions' => 'une région',
        'provinces' => 'une province',
        'complexes' => 'un complexe / CMC',
        'etablissements' => 'un établissement',
        'internats' => 'un internat',
    ];

    private const CHAMPS = [
        'regions' => ['code', 'nom'],
        'provinces' => ['region_id', 'code', 'nom'],
        'complexes' => ['province_id', 'code', 'nom'],
        'etablissements' => ['complexe_id', 'code_efp', 'nom_efp'],
        'internats' => ['etablissement_id', 'genre', 'nom'],
    ];

    /** Niveau de l'élément saisi : régions, provinces, complexes, établissements ou internats. */
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

        // Valeurs proposées : premier parent de la hiérarchie, les filles pour l'internat.
        match ($niveau) {
            'provinces' => $this->saisie['region_id'] = (string) (Region::orderBy('nom')->value('id') ?? ''),
            'complexes' => $this->saisie['province_id'] = (string) (Province::orderBy('nom')->value('id') ?? ''),
            'etablissements' => $this->saisie['complexe_id'] = (string) (Complexe::orderBy('nom')->value('id') ?? ''),
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
            'saisie.region_id' => 'région', 'saisie.province_id' => 'province', 'saisie.complexe_id' => 'complexe',
            'saisie.etablissement_id' => 'établissement', 'saisie.genre' => 'genre',
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
        $provinces = Province::orderBy('nom')->get();
        $complexes = Complexe::orderBy('nom')->get();
        $etablissements = Etablissement::orderBy('nom_efp')->get();

        return view('livewire.administration.organisation', [
            'regions' => $regions,
            'provinces' => $provinces->groupBy('region_id'),
            'nomsProvinces' => $provinces->pluck('nom', 'id'),
            'complexes' => $complexes->groupBy('province_id'),
            'nomsComplexes' => $complexes->pluck('nom', 'id'),
            'etablissements' => $etablissements->groupBy('complexe_id'),
            'internats' => Internat::orderBy('nom')->get()->groupBy('etablissement_id'),
            'titre' => ($this->ligne ? 'Modifier ' : 'Ajouter ').(self::TITRES[$this->niveau] ?? ''),
            // Listes de choix de la fenêtre (chaque niveau choisit son parent direct).
            'choixRegions' => $regions->pluck('nom', 'id'),
            'choixProvinces' => $provinces->pluck('nom', 'id'),
            'choixComplexes' => $complexes->pluck('nom', 'id'),
            'choixEtablissements' => $etablissements->pluck('nom_efp', 'id'),
        ]);
    }
}
