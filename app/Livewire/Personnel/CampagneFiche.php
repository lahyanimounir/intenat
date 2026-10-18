<?php

namespace App\Livewire\Personnel;

use App\Domain\Admissions\CalculScore;
use App\Domain\Campaigns\GestionCampagne;
use App\Domain\Campaigns\GrilleCampagne;
use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Livewire\PagePersonnel;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\TypePiece;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Fiche d'une campagne : paramètres, pièces exigées et grille de critères.
 * Pièces et critères se saisissent en préparation. Les critères sont figés
 * (définis au niveau national) et ne se corrigent pas en cours de campagne.
 */
#[Title('Campagne')]
class CampagneFiche extends PagePersonnel
{
    private const ONGLETS = ['synthese', 'pieces', 'grille'];

    #[Locked]
    public int $campagneId;

    #[Url(except: 'synthese')]
    public string $onglet = 'synthese';

    /** Identifiant de la ligne en cours de modification dans la fenêtre ouverte, ou null pour une création. */
    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, mixed> */
    public array $parametres = [];

    /** @var array<string, mixed> */
    public array $piece = ['libelle' => '', 'obligatoire' => true, 'formats_acceptes' => 'PDF, JPG, PNG, 5 Mo'];

    /** @var array<string, mixed> */
    public array $critere = ['categorie' => '', 'declencheur' => '', 'valeur_attendue' => '', 'condition' => '', 'points' => '', 'type_piece_id' => ''];

    public function mount(Campagne $campagne): void
    {
        $this->autoriser($campagne, 'campagnes.consulter');
        $this->campagneId = $campagne->id;

        if (! in_array($this->onglet, self::ONGLETS, true)) {
            $this->onglet = 'synthese';
        }
    }

    private function campagne(): Campagne
    {
        $campagne = Campagne::findOrFail($this->campagneId);
        $this->autoriser($campagne, 'campagnes.consulter');

        return $campagne;
    }

    public function voir(string $onglet): void
    {
        $this->onglet = in_array($onglet, self::ONGLETS, true) ? $onglet : 'synthese';
    }

    /* ----------------------------------------------------------- Paramètres */

    public function preparerModification(): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');

        $this->parametres = [
            'annee' => $campagne->annee,
            'date_ouverture' => $campagne->date_ouverture->toDateString(),
            'date_cloture' => $campagne->date_cloture->toDateString(),
            'nombre_places' => $campagne->nombre_places,
            'frais' => $campagne->frais,
        ];
        $this->ouvrirDialogue('parametres');
    }

    public function modifier(GestionCampagne $campagnes): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');

        $valides = $this->validate([
            'parametres.annee' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'parametres.date_ouverture' => ['required', 'date'],
            'parametres.date_cloture' => ['required', 'date'],
            'parametres.nombre_places' => ['required', 'integer', 'min:1', 'max:5000'],
            'parametres.frais' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ], ['parametres.annee.regex' => 'Année attendue au format 2026-2027.'], [
            'parametres.annee' => 'année', 'parametres.date_ouverture' => 'date d\'ouverture', 'parametres.date_cloture' => 'date de clôture',
            'parametres.nombre_places' => 'nombre de places', 'parametres.frais' => 'frais mensuels d’internat',
        ])['parametres'];

        $valides['frais'] = ($valides['frais'] ?? '') === '' ? null : $valides['frais'];

        // Après l'ouverture, seuls la clôture et le nombre de places restent ajustables : le reste n'est pas transmis.
        if ($campagne->statut !== StatutCampagne::Preparee) {
            $valides = array_intersect_key($valides, array_flip(['date_cloture', 'nombre_places']));
        }

        if ($this->executer(fn () => $campagnes->modifier($campagne, $valides), 'Campagne modifiée.')) {
            $this->fermerDialogue();
        }
    }

    public function transiter(GestionCampagne $campagnes, string $action): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');

        match ($action) {
            'ouvrir' => $this->executer(fn () => $campagnes->ouvrir($campagne), 'Campagne ouverte : les stagiaires peuvent déposer leur dossier.'),
            'cloturer' => $this->executer(fn () => $campagnes->cloturer($campagne), 'Campagne clôturée : plus aucun dépôt n\'est accepté.'),
            'archiver' => $this->executer(fn () => $campagnes->archiver($campagne), 'Campagne archivée.'),
            default => abort(404),
        };
    }

    public function supprimer(GestionCampagne $campagnes): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');

        if ($this->executer(fn () => $campagnes->supprimer($campagne))) {
            session()->flash('succes', 'Campagne supprimée.');
            $this->redirect(route('campagnes'), navigate: true);
        }
    }

    /* -------------------------------------------------------- Pièces exigées */

    public function preparerPiece(?int $id = null): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');
        $type = $id !== null ? TypePiece::where('campagne_id', $campagne->id)->findOrFail($id) : null;

        $this->ligne = $type?->id;
        $this->piece = [
            'libelle' => $type->libelle ?? '',
            'obligatoire' => $type->obligatoire ?? true,
            'formats_acceptes' => $type->formats_acceptes ?? 'PDF, JPG, PNG, 5 Mo',
        ];
        $this->ouvrirDialogue('piece');
    }

    public function enregistrerPiece(GrilleCampagne $grille): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');
        $type = $this->ligne !== null ? TypePiece::where('campagne_id', $campagne->id)->findOrFail($this->ligne) : null;

        $valides = $this->validate([
            'piece.libelle' => ['required', 'string', 'max:150', Rule::unique('type_piece', 'libelle')->where('campagne_id', $campagne->id)->ignore($type?->id)],
            'piece.obligatoire' => ['required', 'boolean'],
            'piece.formats_acceptes' => ['required', 'string', 'max:100'],
        ], ['piece.libelle.unique' => 'Une pièce porte déjà ce libellé dans cette campagne.'], [
            'piece.libelle' => 'libellé', 'piece.formats_acceptes' => 'formats acceptés',
        ])['piece'];

        if ($this->executer(fn () => $grille->enregistrerTypePiece($campagne, $type, $valides), $type ? 'Pièce modifiée.' : 'Pièce ajoutée.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimerPiece(GrilleCampagne $grille, int $id): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'campagnes.gerer');

        $this->executer(fn () => $grille->supprimerTypePiece($campagne, TypePiece::where('campagne_id', $campagne->id)->findOrFail($id)), 'Pièce supprimée.');
    }

    /* -------------------------------------------------------------- Critères */

    public function preparerCritere(?int $id = null): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'criteres.gerer');
        $critere = $id !== null ? Critere::where('campagne_id', $campagne->id)->findOrFail($id) : null;

        $this->ligne = $critere?->id;
        $this->critere = [
            'categorie' => $critere?->categorie->value ?? '',
            'declencheur' => $critere?->declencheur->value ?? '',
            'valeur_attendue' => $critere->valeur_attendue ?? '',
            'condition' => $critere->condition_texte ?? '',
            'points' => $critere->points ?? '',
            'type_piece_id' => $critere->type_piece_id ?? '',
        ];
        $this->ouvrirDialogue('critere');
    }

    public function enregistrerCritere(GrilleCampagne $grille): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'criteres.gerer');
        $critere = $this->ligne !== null ? Critere::where('campagne_id', $campagne->id)->findOrFail($this->ligne) : null;

        $valides = $this->validate([
            'critere.categorie' => ['required', Rule::enum(CategorieCritere::class)],
            'critere.declencheur' => ['required', Rule::enum(DeclencheurCritere::class)],
            'critere.valeur_attendue' => [
                Rule::requiredIf(($this->critere['declencheur'] ?? '') === DeclencheurCritere::NiveauFormation->value),
                'nullable', Rule::enum(NiveauFormation::class),
            ],
            'critere.condition' => ['required', 'string', 'max:255'],
            'critere.points' => ['required', 'integer', 'min:0', 'max:80'],
            'critere.type_piece_id' => ['nullable', 'integer', Rule::exists('type_piece', 'id')->where('campagne_id', $campagne->id)],
        ], ['critere.valeur_attendue.required' => 'Précisez le niveau de formation visé par ce critère.'], [
            'critere.categorie' => 'catégorie', 'critere.declencheur' => 'donnée du dossier', 'critere.valeur_attendue' => 'niveau de formation',
            'critere.condition' => 'condition', 'critere.points' => 'points', 'critere.type_piece_id' => 'pièce justificative',
        ])['critere'];

        $donnees = [
            'categorie' => $valides['categorie'],
            'declencheur' => $valides['declencheur'],
            'valeur_attendue' => $valides['valeur_attendue'] ?? '',
            'condition_texte' => $valides['condition'],
            'points' => (int) $valides['points'],
            'type_piece_id' => ($valides['type_piece_id'] ?? '') === '' ? null : (int) $valides['type_piece_id'],
        ];

        if ($this->executer(fn () => $grille->enregistrerCritere($campagne, $critere, $donnees), $critere ? 'Critère modifié.' : 'Critère ajouté.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimerCritere(GrilleCampagne $grille, int $id): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'criteres.gerer');

        $this->executer(fn () => $grille->supprimerCritere($campagne, Critere::where('campagne_id', $campagne->id)->findOrFail($id)), 'Critère supprimé.');
    }

    public function appliquerReference(GestionCampagne $campagnes): void
    {
        $campagne = $this->campagne();
        $this->autoriser($campagne, 'criteres.gerer');

        $this->executer(function () use ($campagnes, $campagne) {
            $campagnes->exigerPreparation($campagne);
            $copies = $campagnes->appliquerGrilleDeReference($campagne);
            $this->message($copies > 0 ? "{$copies} critère(s) repris de la grille de référence." : 'La grille contient déjà tous les critères de référence.', $copies > 0 ? 'succes' : 'info');
        });
    }

    /* ----------------------------------------------------------- Corrections */

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $campagne = $this->campagne()->load('internat.etablissement', 'createur', 'typesPieces', 'criteres.typePiece');
        $utilisateur = $this->utilisateur();
        $gestion = app(GestionCampagne::class);
        $preparation = $campagne->statut === StatutCampagne::Preparee;
        $perimetre = $this->perimetre()->couvreInternat($utilisateur, $campagne->internat_id);

        return view('livewire.personnel.campagne-fiche', [
            'campagne' => $campagne,
            'preparation' => $preparation,
            'gerer' => $perimetre && $utilisateur->can('campagnes.gerer'),
            'gererCriteres' => $perimetre && $utilisateur->can('criteres.gerer') && $preparation,
            'typesPieces' => $campagne->typesPieces->sortBy('libelle')->values(),
            'criteres' => $campagne->criteres->sortBy('id')->values(),
            'maximum' => app(CalculScore::class)->maximumAtteignable($campagne),
            'plafond' => (int) config('internat.score.bareme_maximum'),
            'litsEligibles' => $gestion->litsEligibles($campagne->internat_id),
            'blocages' => $preparation ? $gestion->controlesOuverture($campagne) : [],
            'dossiersParStatut' => $campagne->demandes()->where('statut', '!=', StatutDemande::Brouillon->value)
                ->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut'),
        ]);
    }
}
