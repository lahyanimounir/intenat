<?php

namespace App\Livewire\Personnel;

use App\Domain\Campaigns\GestionCampagne;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Domain\Identity\ComptesPersonnel;
use App\Livewire\Concerns\AvecHierarchie;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Campagne;
use App\Models\Internat;
use App\Support\AnneeScolaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/** Campagnes d'admission du périmètre. Le chef de pôle en crée une par internat et par année, avec son nombre de places. */
#[Title('Campagnes')]
class Campagnes extends PagePersonnel
{
    use AvecHierarchie, AvecTableau;

    #[Url(except: '')]
    public string $statut = '';

    /** Dernier niveau de la hiérarchie : sans niveau choisi au-dessus, tous les internats du périmètre restent proposés. */
    #[Url(except: '')]
    public string $internat = '';

    #[Url(except: false)]
    public bool $archives = false;

    /** @var array<string, string> */
    public array $nouvelle = ['annee' => '', 'date_ouverture' => '', 'date_cloture' => '', 'frais' => ''];

    /** Places offertes par internat ; une ligne laissée vide ne crée pas de campagne. */
    public array $places = [];

    public function mount(): void
    {
        $this->exiger('campagnes.consulter');
    }

    protected function filtres(): array
    {
        return [...$this->filtresHierarchiques(), 'statut', 'internat', 'archives'];
    }

    /* ------------------------------------------- Hiérarchie : changer un niveau efface ceux d'en dessous */

    public function updatedRegion(): void
    {
        $this->reset('province', 'complexe', 'etablissement', 'internat');
    }

    public function updatedProvince(): void
    {
        $this->reset('complexe', 'etablissement', 'internat');
    }

    public function updatedComplexe(): void
    {
        $this->reset('etablissement', 'internat');
    }

    public function updatedEtablissement(): void
    {
        $this->reset('internat');
    }

    /**
     * Internats proposés au dernier niveau : ceux des établissements couverts par la hiérarchie choisie,
     * ou, sans choix, tous ceux du périmètre (comportement d'origine du filtre « Internat »).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function internatsFiltres(): \Illuminate\Support\Collection
    {
        $etablissements = $this->etablissementsFiltres();

        return Internat::visiblesPar($this->utilisateur())
            ->when($etablissements !== null, fn (Builder $q) => $q->whereIn('etablissement_id', $etablissements))
            ->orderBy('nom')->pluck('nom', 'id');
    }

    public function preparer(): void
    {
        $this->exiger('campagnes.gerer');
        $this->nouvelle = ['annee' => AnneeScolaire::courante(), 'date_ouverture' => now()->toDateString(), 'date_cloture' => '', 'frais' => ''];
        $this->places = [];
        $this->ouvrirDialogue('creation');
    }

    public function creer(GestionCampagne $campagnes): void
    {
        $this->exiger('campagnes.gerer');

        $valides = $this->validate([
            'nouvelle.annee' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'nouvelle.date_ouverture' => ['required', 'date'],
            'nouvelle.date_cloture' => ['required', 'date', 'after_or_equal:nouvelle.date_ouverture'],
            'nouvelle.frais' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'places.*' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], [
            'nouvelle.annee.regex' => 'Année attendue au format 2026-2027.',
            'nouvelle.date_cloture.after_or_equal' => 'La clôture ne peut pas précéder l\'ouverture.',
        ], [
            'nouvelle.annee' => 'année', 'nouvelle.date_ouverture' => 'date d\'ouverture', 'nouvelle.date_cloture' => 'date de clôture',
            'nouvelle.frais' => 'frais mensuels d’internat', 'places.*' => 'nombre de places',
        ]);

        [$debut, $fin] = array_map('intval', explode('-', $valides['nouvelle']['annee']));

        if ($fin !== $debut + 1) {
            throw ValidationException::withMessages(['nouvelle.annee' => 'L\'année de formation couvre deux années consécutives (ex. 2026-2027).']);
        }

        $internats = collect($this->places)->filter(fn ($nombre) => $nombre !== null && $nombre !== '')
            ->map(fn ($nombre, $id) => ['internat_id' => (int) $id, 'nombre_places' => (int) $nombre])->values()->all();

        if ($internats === []) {
            throw ValidationException::withMessages(['places' => 'Indiquez le nombre de places d\'au moins un internat.']);
        }

        $donnees = [
            'annee' => $valides['nouvelle']['annee'],
            'date_ouverture' => $valides['nouvelle']['date_ouverture'],
            'date_cloture' => $valides['nouvelle']['date_cloture'],
            'frais' => ($valides['nouvelle']['frais'] ?? '') === '' ? null : $valides['nouvelle']['frais'],
            'internats' => $internats,
        ];

        $creees = [];

        if ($this->executer(function () use ($campagnes, $donnees, &$creees) {
            $creees = $campagnes->creer($this->utilisateur(), $donnees);
        }, 'Campagne créée, en préparation. Complétez ses pièces et sa grille avant de l\'ouvrir.')) {
            $this->fermerDialogue();

            if (count($creees) === 1) {
                $this->redirect(route('campagnes.fiche', $creees[0]), navigate: true);
            }
        }
    }

    public function render()
    {
        $this->exiger('campagnes.consulter');
        $utilisateur = $this->utilisateur();

        // Un internat donné seul (adresse, ancien lien) fixe les niveaux au-dessus de lui.
        if ($this->internat !== '' && $this->region === '' && $this->internatsDuPerimetre()->has((int) $this->internat)) {
            $chaine = ComptesPersonnel::ascendance('internat_id', (int) $this->internat);
            [$this->region, $this->province, $this->complexe, $this->etablissement] = array_map(
                fn ($id) => (string) ($id ?? ''),
                [$chaine['region_id'], $chaine['province_id'], $chaine['complexe_id'], $chaine['etablissement_id']],
            );
        }

        $filtre = $this->listesDesFiltres();
        $internats = $this->internatsFiltres();

        if ($this->internat !== '' && ! $internats->has((int) $this->internat)) {
            $this->reset('internat');
        }

        $etablissements = $this->etablissementsFiltres();

        $requete = Campagne::visiblesPar($utilisateur)
            ->with('internat.etablissement:id,nom_efp,code_efp', 'createur:id,nom,prenom')
            ->withCount(['demandes' => fn (Builder $q) => $q->where('statut', '!=', StatutDemande::Brouillon->value)])
            ->when($this->statut !== '', fn (Builder $q) => $q->where('statut', $this->statut))
            ->when($this->internat !== '', fn (Builder $q) => $q->where('internat_id', (int) $this->internat))
            ->when($this->internat === '' && $etablissements !== null, fn (Builder $q) => $q->whereIn('internat_id', Internat::whereIn('etablissement_id', $etablissements)->select('id')))
            ->when(! $this->archives, fn (Builder $q) => $q->whereNull('archivee_le'))
            ->orderByDesc('annee')->orderBy('internat_id')->orderByDesc('date_ouverture');

        $gerer = $utilisateur->can('campagnes.gerer');

        return view('livewire.personnel.campagnes', [
            'campagnes' => $this->paginer($requete),
            'statuts' => StatutCampagne::options(),
            'internats' => $internats,
            'plusieursInternats' => $this->internatsDuPerimetre()->count() > 1,
            'filtre' => $filtre,
            'gerer' => $gerer,
            // Internats proposés à la création, avec le nombre de lits que leur référentiel permet d'offrir.
            'internatsCreation' => $gerer && $this->dialogue === 'creation'
                ? Internat::visiblesPar($utilisateur)->orderBy('nom')->get()->map(fn (Internat $i) => (object) [
                    'id' => $i->id, 'nom' => $i->nom, 'lits' => app(GestionCampagne::class)->litsEligibles($i->id),
                ])
                : collect(),
        ]);
    }
}
