<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\ImportReferentielCsv;
use App\Enums\StatutImport;
use App\Livewire\PagePersonnel;
use App\Models\ImportReferentiel as Import;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Import du référentiel d'un internat depuis un fichier CSV, en deux temps :
 * analyse sans rien écrire, puis confirmation d'un fichier sans erreur. Chaque
 * gestionnaire importe les bâtiments, chambres et lits de son seul internat ;
 * l'internat visé vient du compte, jamais du fichier.
 */
#[Title('Import du référentiel')]
class ImportReferentiel extends PagePersonnel
{
    use WithFileUploads, WithPagination;

    private const PERMISSION = 'referentiel.gerer';

    #[Url(except: '')]
    public string $internat = '';

    /** @var TemporaryUploadedFile|null */
    public $fichier = null;

    /** Import dont l'analyse est affichée. */
    #[Locked]
    public ?int $analyseId = null;

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    private function import(int $id): Import
    {
        $import = Import::findOrFail($id);
        $this->autoriser($import, self::PERMISSION);

        return $import;
    }

    public function analyser(ImportReferentielCsv $service): void
    {
        $this->exiger(self::PERMISSION);
        $internat = $this->internatChoisi($this->internat);
        abort_if($internat === null, 404);

        $this->validate(['fichier' => ['required', 'file', 'max:2048']], [
            'fichier.required' => 'Choisissez le fichier CSV du référentiel.',
            'fichier.max' => 'Le fichier dépasse 2 Mo.',
        ], ['fichier' => 'fichier']);

        $this->executer(function () use ($service, $internat) {
            $this->analyseId = $service->analyser($this->fichier, $internat, $this->utilisateur())->id;
        });

        $this->reset('fichier');
    }

    public function confirmer(ImportReferentielCsv $service): void
    {
        $import = $this->import((int) $this->analyseId);

        $this->executer(fn () => $service->confirmer($import, $this->utilisateur()), 'Référentiel importé.');
    }

    public function annuler(ImportReferentielCsv $service): void
    {
        $import = $this->import((int) $this->analyseId);

        if ($this->executer(fn () => $service->annuler($import), 'Import annulé : rien n\'a été écrit dans le référentiel.')) {
            $this->analyseId = null;
        }
    }

    public function rouvrir(int $id): void
    {
        $this->analyseId = $this->import($id)->id;
    }

    public function fermerAnalyse(): void
    {
        $this->analyseId = null;
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);
        $utilisateur = $this->utilisateur();
        $analyse = $this->analyseId !== null ? Import::with('internat:id,nom')->find($this->analyseId) : null;

        if ($analyse !== null) {
            $this->autoriser($analyse, self::PERMISSION);
        }

        $rapport = $analyse?->rapport ?? [];

        return view('livewire.personnel.import-referentiel', [
            'choisi' => $this->internatChoisi($this->internat),
            'internats' => $this->internatsDuPerimetre(),
            'analyse' => $analyse,
            'rapport' => $rapport,
            'importable' => $analyse !== null && $analyse->statut === StatutImport::Analyse
                && $analyse->lignes_erreur === 0 && ($rapport['erreurs_fichier'] ?? []) === [] && $analyse->lignes_valides > 0,
            'historique' => Import::visiblesPar($utilisateur)->with('internat:id,nom', 'auteur:id,nom,prenom')->latest('id')->paginate(10),
        ]);
    }
}
