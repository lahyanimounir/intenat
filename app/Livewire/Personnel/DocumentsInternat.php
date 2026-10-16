<?php

namespace App\Livewire\Personnel;

use App\Domain\Residence\TenueInternat;
use App\Enums\TypeDocument;
use App\Livewire\PagePersonnel;
use App\Models\Document;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\WithFileUploads;

/**
 * Documents utiles d'un internat : règlement intérieur, dates d'arrivée, notes
 * d'information. Un document publié est lisible par les stagiaires de
 * l'internat, et par eux seuls ; le fichier reste en espace privé.
 */
#[Title('Documents utiles')]
class DocumentsInternat extends PagePersonnel
{
    use WithFileUploads;

    private const PERMISSION = 'documents.gerer';

    /** @var array<string, string> */
    public array $saisie = ['internat_id' => '', 'titre' => '', 'type' => ''];

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $fichier = null;

    /** Document ouvert en aperçu. */
    #[Locked]
    public ?int $ligne = null;

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    public function preparer(): void
    {
        $this->exiger(self::PERMISSION);
        $this->saisie = [
            'internat_id' => (string) ($this->internatsDuPerimetre()->keys()->first() ?? ''),
            'titre' => '', 'type' => TypeDocument::cases()[0]->value,
        ];
        $this->fichier = null;
        $this->ouvrirDialogue('publication');
    }

    public function publier(TenueInternat $tenue): void
    {
        $this->exiger(self::PERMISSION);

        $valides = $this->validate([
            'saisie.internat_id' => ['required'],
            'saisie.titre' => ['required', 'string', 'max:190'],
            'saisie.type' => ['required', Rule::enum(TypeDocument::class)],
            'fichier' => ['required', 'file'],
        ], ['saisie.internat_id.required' => 'Choisissez l\'internat concerné.', 'fichier.required' => 'Choisissez le fichier à publier.'], [
            'saisie.titre' => 'titre', 'saisie.type' => 'nature', 'fichier' => 'fichier',
        ])['saisie'];

        $internat = $this->internatChoisi($valides['internat_id']);
        abort_if($internat === null, 404);

        if ($this->executer(fn () => $tenue->publierDocument($internat, trim($valides['titre']), TypeDocument::from($valides['type']), $this->fichier, $this->utilisateur()),
            'Document publié : il est visible des stagiaires de l\'internat.')) {
            $this->fermerDialogue();
        }
    }

    public function consulter(int $id): void
    {
        $this->ligne = $this->document($id)->id;
        $this->ouvrirDialogue('apercu');
    }

    public function retirer(TenueInternat $tenue, int $id): void
    {
        $document = $this->document($id);

        $this->executer(fn () => $tenue->retirerDocument($document), 'Document retiré.');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->fichier = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    private function document(int $id): Document
    {
        $document = Document::findOrFail($id);
        $this->autoriser($document, self::PERMISSION);

        return $document;
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);

        return view('livewire.personnel.documents-internat', [
            'documents' => Document::visiblesPar($this->utilisateur())->with('internat:id,nom', 'auteur:id,nom,prenom')->latest('date_publication')->get(),
            'internats' => $this->internatsDuPerimetre(),
            'apercu' => $this->dialogue === 'apercu' && $this->ligne !== null ? Document::find($this->ligne) : null,
        ]);
    }
}
