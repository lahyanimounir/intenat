<?php

namespace App\Domain\Residence;

use App\Domain\Audit\Journal;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\TypeDocument;
use App\Enums\TypeIncident;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Internat;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Tenue courante d'un internat par son gestionnaire : incidents, annulation
 * d'une absence saisie par erreur, documents utiles.
 * Chaque écriture est journalisée ; un fichier joint est contrôlé puis conservé
 * en espace privé.
 */
class TenueInternat
{
    public function __construct(
        private readonly Journal $journal,
        private readonly Documents $documents,
    ) {}

    public function enregistrerIncident(Affectation $affectation, TypeIncident $type, string $description, CarbonInterface $date, ?UploadedFile $rapport, Utilisateur $gestionnaire): Incident
    {
        $chemin = $rapport !== null
            ? $this->documents->enregistrer($rapport, "incidents/{$affectation->id}", ['pdf'], (int) config('internat.documents.taille_max_ko'))['chemin']
            : null;

        $incident = Incident::create([
            'affectation_id' => $affectation->id, 'type' => $type, 'description' => $description,
            'date_incident' => $date, 'rapport_fichier' => $chemin, 'saisi_par' => $gestionnaire->id,
        ]);

        $this->journal->consigner('incident.creation', $incident, details: ['type' => $type->value, 'affectation_id' => $affectation->id], acteur: $gestionnaire);

        return $incident;
    }

    /** Annule une absence saisie par erreur : la trace de l'annulation reste au journal. */
    public function annulerAbsence(Absence $absence): void
    {
        $this->journal->consigner('absence.annulation', $absence, details: [
            'affectation_id' => $absence->affectation_id, 'date' => $absence->date_absence->toDateString(), 'autorisee' => $absence->autorisee,
        ]);

        $absence->delete();
    }

    public function publierDocument(Internat $internat, string $titre, TypeDocument $type, UploadedFile $fichier, Utilisateur $auteur): Document
    {
        $stocke = $this->documents->enregistrer($fichier, "documents/{$internat->id}", ['pdf'], (int) config('internat.documents.taille_max_ko'));

        $document = Document::create([
            'internat_id' => $internat->id, 'titre' => $titre, 'type' => $type,
            'fichier' => $stocke['chemin'], 'nom_original' => $stocke['nom_original'], 'mime' => $stocke['mime'],
            'date_publication' => now(), 'publie_par' => $auteur->id,
        ]);

        $this->journal->consigner('document.publication', $document, details: ['titre' => $document->titre, 'internat_id' => $internat->id], acteur: $auteur);

        return $document;
    }

    public function retirerDocument(Document $document): void
    {
        $this->journal->consigner('document.suppression', $document, details: ['titre' => $document->titre]);

        $chemin = $document->fichier;
        $document->delete();
        $this->documents->supprimer($chemin);
    }
}
