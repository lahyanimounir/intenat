<?php

namespace App\Livewire\Personnel;

use App\Domain\Campaigns\GestionCampagne;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
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
    use AvecTableau;

    #[Url(except: '')]
    public string $statut = '';

    #[Url(except: '')]
    public string $internat = '';

    #[Url(except: false)]
    public bool $archives = false;

    /** @var array<string, string> */
    public array $nouvelle = ['annee' => '', 'date_ouverture' => '', 'date_cloture' => '', 'frais_mensuels' => ''];

    /** Places offertes par internat ; une ligne laissée vide ne crée pas de campagne. */
    public array $places = [];

    public function mount(): void
    {
        $this->exiger('campagnes.consulter');
    }

    protected function filtres(): array
    {
        return ['statut', 'internat', 'archives'];
    }

    public function preparer(): void
    {
        $this->exiger('campagnes.gerer');
        $this->nouvelle = ['annee' => AnneeScolaire::courante(), 'date_ouverture' => now()->toDateString(), 'date_cloture' => '', 'frais_mensuels' => ''];
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
            'nouvelle.frais_mensuels' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'places.*' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ], [
            'nouvelle.annee.regex' => 'Année attendue au format 2026-2027.',
            'nouvelle.date_cloture.after_or_equal' => 'La clôture ne peut pas précéder l\'ouverture.',
        ], [
            'nouvelle.annee' => 'année', 'nouvelle.date_ouverture' => 'date d\'ouverture', 'nouvelle.date_cloture' => 'date de clôture',
            'nouvelle.frais_mensuels' => 'frais mensuels', 'places.*' => 'nombre de places',
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
            'frais_mensuels' => ($valides['nouvelle']['frais_mensuels'] ?? '') === '' ? null : $valides['nouvelle']['frais_mensuels'],
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

        $requete = Campagne::visiblesPar($utilisateur)
            ->with('internat.etablissement:id,nom_efp,code_efp', 'createur:id,nom,prenom')
            ->withCount(['demandes' => fn (Builder $q) => $q->where('statut', '!=', StatutDemande::Brouillon->value)])
            ->when($this->statut !== '', fn (Builder $q) => $q->where('statut', $this->statut))
            ->when($this->internat !== '', fn (Builder $q) => $q->where('internat_id', (int) $this->internat))
            ->when(! $this->archives, fn (Builder $q) => $q->whereNull('archivee_le'))
            ->orderByDesc('annee')->orderBy('internat_id');

        $gerer = $utilisateur->can('campagnes.gerer');

        return view('livewire.personnel.campagnes', [
            'campagnes' => $this->paginer($requete),
            'statuts' => StatutCampagne::options(),
            'internats' => $this->internatsDuPerimetre(),
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
