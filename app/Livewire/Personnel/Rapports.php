<?php

namespace App\Livewire\Personnel;

use App\Domain\Reporting\Exports;
use App\Enums\StatutExport;
use App\Livewire\PagePersonnel;
use App\Models\Campagne;
use App\Models\Export;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

/**
 * Rapports et exports. Un export est produit en arrière-plan, avec les mêmes
 * portées de périmètre que les écrans : on n'exporte jamais une ligne que l'on
 * ne peut pas consulter. Seul son demandeur peut le télécharger.
 */
#[Title('Rapports et exports')]
class Rapports extends PagePersonnel
{
    use WithPagination;

    private const PERMISSION = 'exports.creer';

    /** Types qui n'ont de sens que pour une campagne donnée. */
    private const CAMPAGNE_EXIGEE = ['liste_admis', 'liste_attente'];

    private const DESCRIPTIONS = [
        'residents' => 'Résidents hébergés à ce jour, avec bâtiment, chambre et lit.',
        'occupation' => 'État de chaque chambre : capacité, lits occupés et hors service.',
        'dossiers' => 'Dossiers déposés et leur statut. Le score et le rang n’y figurent pas.',
        'liste_admis' => 'Liste principale d’une campagne, classée par rang.',
        'liste_attente' => 'Liste d’attente d’une campagne, classée par rang.',
        'paiements' => 'Autorisations, reçus et contrôles par période.',
        'effectifs_repas' => 'Réservés, confirmés et servis pour chaque repas du mois.',
        'indicateurs' => 'Capacité, occupation et admissions par établissement.',
    ];

    public string $type = '';

    public string $format = 'pdf';

    public string $campagne = '';

    public string $mois = '';

    public function mount(Exports $exports): void
    {
        $this->exiger(self::PERMISSION);
        $this->type = $exports->typesPour($this->utilisateur())[0]['type'] ?? '';
        $this->mois = now()->format('Y-m');
    }

    public function demander(Exports $exports): void
    {
        $this->exiger(self::PERMISSION);
        $types = collect($exports->typesPour($this->utilisateur()))->keyBy('type');

        $valides = $this->validate([
            'type' => ['required', Rule::in($types->keys()->all())],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'campagne' => [in_array($this->type, self::CAMPAGNE_EXIGEE, true) ? 'required' : 'nullable', 'integer'],
            'mois' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], ['campagne.required' => 'Choisissez la campagne à exporter.', 'type.in' => 'Cet export n\'est pas disponible pour votre rôle.'], ['type' => 'contenu']);

        $choisi = $types[$valides['type']];
        $filtres = [];

        if ($choisi['campagne'] && $valides['campagne'] !== '' && $valides['campagne'] !== null) {
            // La campagne est prise dans le périmètre : une campagne étrangère n'existe pas pour ce compte.
            $filtres['campagne_id'] = Campagne::visiblesPar($this->utilisateur())->findOrFail((int) $valides['campagne'])->id;
        }

        if ($valides['type'] === 'paiements' && $valides['mois']) {
            $filtres['periode'] = $valides['mois'];
        }

        if ($valides['type'] === 'effectifs_repas' && $valides['mois']) {
            $filtres['mois'] = $valides['mois'];
        }

        if ($this->executer(fn () => $exports->demander($this->utilisateur(), $valides['type'], $valides['format'], $filtres), 'Export demandé : il apparaît ci-contre dès qu’il est prêt.')) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $exports = app(Exports::class);
        $utilisateur = $this->utilisateur();
        $types = collect($exports->typesPour($utilisateur))->keyBy('type');
        $liste = Export::where('utilisateur_id', $utilisateur->id)->latest('id')->paginate(15);

        return view('livewire.personnel.rapports', [
            'types' => $types,
            'choisi' => $types->get($this->type),
            'description' => self::DESCRIPTIONS[$this->type] ?? null,
            'exigeCampagne' => in_array($this->type, self::CAMPAGNE_EXIGEE, true),
            'avecMois' => in_array($this->type, ['paiements', 'effectifs_repas'], true),
            'campagnes' => $this->campagnesDuPerimetre(),
            'exports' => $liste,
            'libelles' => collect(Exports::TYPES)->map(fn (array $t) => $t['libelle']),
            // Tant qu'un export est en préparation, la liste se rafraîchit toute seule.
            'enPreparation' => $liste->contains(fn (Export $e) => in_array($e->statut, [StatutExport::EnAttente, StatutExport::EnCours], true)),
        ]);
    }
}
