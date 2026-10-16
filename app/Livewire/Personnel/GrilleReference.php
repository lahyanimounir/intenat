<?php

namespace App\Livewire\Personnel;

use App\Domain\Admissions\CalculScore;
use App\Domain\Campaigns\GrilleCampagne;
use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\NiveauFormation;
use App\Livewire\PagePersonnel;
use App\Models\CritereReference;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/** Grille nationale de critères de référence, tenue par la direction centrale et recopiée dans chaque nouvelle campagne. */
#[Title('Grille de référence')]
class GrilleReference extends PagePersonnel
{
    private const PERMISSION = 'grille_reference.gerer';

    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, mixed> */
    public array $critere = [];

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    public function preparer(?int $id = null): void
    {
        $this->exiger(self::PERMISSION);
        $critere = $id !== null ? CritereReference::findOrFail($id) : null;

        $this->ligne = $critere?->id;
        $this->critere = [
            'categorie' => $critere?->categorie->value ?? '',
            'declencheur' => $critere?->declencheur->value ?? '',
            'valeur_attendue' => $critere->valeur_attendue ?? '',
            'condition' => $critere->condition_texte ?? '',
            'points' => $critere->points ?? '',
            'piece_libelle' => $critere->piece_libelle ?? '',
            'actif' => $critere->actif ?? true,
        ];
        $this->ouvrirDialogue('critere');
    }

    public function enregistrer(GrilleCampagne $grille): void
    {
        $this->exiger(self::PERMISSION);
        $critere = $this->ligne !== null ? CritereReference::findOrFail($this->ligne) : null;

        $valides = $this->validate([
            'critere.categorie' => ['required', Rule::enum(CategorieCritere::class)],
            'critere.declencheur' => ['required', Rule::enum(DeclencheurCritere::class)],
            'critere.valeur_attendue' => [
                Rule::requiredIf(($this->critere['declencheur'] ?? '') === DeclencheurCritere::NiveauFormation->value),
                'nullable', Rule::enum(NiveauFormation::class),
            ],
            'critere.condition' => ['required', 'string', 'max:255'],
            'critere.points' => ['required', 'integer', 'min:0', 'max:80'],
            'critere.piece_libelle' => ['nullable', 'string', 'max:150'],
            'critere.actif' => ['boolean'],
        ], ['critere.valeur_attendue.required' => 'Précisez le niveau de formation visé par ce critère.'], [
            'critere.categorie' => 'catégorie', 'critere.declencheur' => 'donnée du dossier', 'critere.valeur_attendue' => 'niveau de formation',
            'critere.condition' => 'condition', 'critere.points' => 'points', 'critere.piece_libelle' => 'pièce justificative',
        ])['critere'];

        $donnees = [
            'categorie' => $valides['categorie'],
            'declencheur' => $valides['declencheur'],
            'valeur_attendue' => $valides['valeur_attendue'] ?? '',
            'condition_texte' => $valides['condition'],
            'points' => (int) $valides['points'],
            'piece_libelle' => ($valides['piece_libelle'] ?? '') === '' ? null : $valides['piece_libelle'],
            'actif' => (bool) $valides['actif'],
        ];

        if ($this->executer(fn () => $grille->enregistrerCritereReference($critere, $donnees), $critere ? 'Critère de référence modifié.' : 'Critère de référence ajouté.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimer(GrilleCampagne $grille, int $id): void
    {
        $this->exiger(self::PERMISSION);

        $this->executer(fn () => $grille->supprimerCritereReference(CritereReference::findOrFail($id)), 'Critère de référence supprimé.');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);

        return view('livewire.personnel.grille-reference', [
            'criteres' => CritereReference::orderBy('categorie')->orderBy('id')->get(),
            'maximum' => app(CalculScore::class)->maximumDe(CritereReference::where('actif', true)->get()),
            'plafond' => (int) config('internat.score.bareme_maximum'),
        ]);
    }
}
