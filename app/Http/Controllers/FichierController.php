<?php

namespace App\Http\Controllers;

use App\Domain\Accommodation\ImportReferentielCsv;
use App\Domain\Audit\Journal;
use App\Domain\Catering\StatistiquesRestauration;
use App\Domain\Identity\ControleInscription;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Domain\Shared\Documents;
use App\Enums\Genre;
use App\Enums\StatutDemande;
use App\Enums\StatutExport;
use App\Models\Affectation;
use App\Models\Demande;
use App\Models\Document;
use App\Models\Export;
use App\Models\ImportReferentiel;
use App\Models\Incident;
use App\Models\Internat;
use App\Models\Paiement;
use App\Models\PieceJointe;
use App\Models\RegimeAlimentaire;
use App\Models\VerificationInscription;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diffusion des fichiers privés et des documents générés. Aucun document n'a
 * d'adresse publique : chaque méthode contrôle d'abord que la personne connectée
 * a le droit de le lire, puis le sert depuis l'espace de stockage privé.
 * `?telecharger=1` force le téléchargement au lieu de l'affichage.
 */
class FichierController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    /* ============================================================ Stagiaire */

    /** Justificatif d'inscription : le stagiaire ne lit que les siens. */
    public function justificatifDuStagiaire(Request $request, VerificationInscription $verification): Response
    {
        abort_unless($verification->stagiaire_id === $this->stagiaire()->id && $verification->aUnJustificatif(), 404);

        return $this->servir($request, $verification->fichier, $verification->nom_original ?? 'justificatif', $verification->mime);
    }

    public function pieceDuStagiaire(Request $request, PieceJointe $piece): Response
    {
        abort_unless($piece->demande->stagiaire_id === $this->stagiaire()->id, 404);

        return $this->servir($request, $piece->fichier, $piece->nom_original, $piece->mime);
    }

    public function accuseDuStagiaire(DocumentsAdministratifs $documents): Response
    {
        $demande = $this->stagiaire()->demandeCourante();
        abort_if($demande === null || $demande->numero_dossier === null, 404);

        return $documents->accuseDeReception($demande);
    }

    public function recuDuStagiaire(Request $request, Paiement $paiement): Response
    {
        abort_unless($paiement->demande->stagiaire_id === $this->stagiaire()->id && $paiement->recu_fichier !== null, 404);

        return $this->servir($request, $paiement->recu_fichier, $paiement->recu_nom_original ?? 'recu', $paiement->recu_mime);
    }

    public function autorisationDuStagiaire(Paiement $paiement, DocumentsAdministratifs $documents): Response
    {
        abort_unless($paiement->demande->stagiaire_id === $this->stagiaire()->id, 404);

        return $documents->autorisationDePaiement($paiement);
    }

    public function attestationDuStagiaire(DocumentsAdministratifs $documents): Response
    {
        $affectation = $this->stagiaire()->affectationActive();
        abort_if($affectation === null, 404);

        return $documents->attestationAffectation($affectation);
    }

    /** Document utile : celui de l'internat du stagiaire uniquement. */
    public function documentDuStagiaire(Request $request, Document $document): Response
    {
        abort_unless($document->internat_id === $this->stagiaire()->internat()?->id, 404);

        return $this->servir($request, $document->fichier, $document->nom_original, $document->mime);
    }

    /* ============================================================ Personnel */

    /** Justificatif d'inscription : l'agent habilité ne lit que ceux des stagiaires de son périmètre. */
    public function justificatifPourAgent(Request $request, VerificationInscription $verification, ControleInscription $controle): Response
    {
        $controle->exigerAcces($this->personnel(), $verification->stagiaire);
        abort_unless($verification->aUnJustificatif(), 404);

        return $this->servir($request, $verification->fichier, $verification->nom_original ?? 'justificatif', $verification->mime);
    }

    /** Pièce justificative d'un dossier : permission de consulter les dossiers et périmètre ; la consultation est journalisée. */
    public function pieceDuDossier(Request $request, PieceJointe $piece, Journal $journal): Response
    {
        $this->autoriser($piece->demande, 'demandes.consulter');
        abort_if($piece->demande->statut === StatutDemande::Brouillon, 404);
        $journal->consigner('piece.consultation', $piece);

        return $this->servir($request, $piece->fichier, $piece->nom_original, $piece->mime);
    }

    public function accuseDuDossier(Demande $demande, DocumentsAdministratifs $documents): Response
    {
        $this->autoriser($demande, 'demandes.consulter');
        abort_if($demande->statut === StatutDemande::Brouillon || $demande->numero_dossier === null, 404);

        return $documents->accuseDeReception($demande);
    }

    public function attestationDuResident(Affectation $affectation, DocumentsAdministratifs $documents): Response
    {
        $this->autoriser($affectation, 'hebergement.consulter');

        return $documents->attestationAffectation($affectation);
    }

    /** Reçu de paiement déposé par un stagiaire : la consultation est journalisée. */
    public function recuDuPaiement(Request $request, Paiement $paiement, Journal $journal): Response
    {
        $this->autoriser($paiement, 'paiements.consulter');
        abort_if($paiement->recu_fichier === null, 404);
        $journal->consigner('paiement.consultation_recu', $paiement);

        return $this->servir($request, $paiement->recu_fichier, $paiement->recu_nom_original ?? 'recu', $paiement->recu_mime);
    }

    public function autorisationDuPaiement(Paiement $paiement, DocumentsAdministratifs $documents): Response
    {
        $this->autoriser($paiement, 'paiements.consulter');

        return $documents->autorisationDePaiement($paiement);
    }

    /** Justificatif d'un régime particulier : lisible si son déclarant est hébergé dans le périmètre ; la consultation est journalisée. */
    public function justificatifDuRegime(Request $request, RegimeAlimentaire $regime, StatistiquesRestauration $statistiques, Journal $journal): Response
    {
        abort_unless($statistiques->couvre($this->personnel(), $regime) && $regime->justificatif_fichier !== null, 404);
        $journal->consigner('regime.consultation_justificatif', $regime);

        // Le type n'est pas conservé avec le régime : il se déduit de l'extension, contrôlée au dépôt.
        $extension = mb_strtolower(pathinfo($regime->justificatif_fichier, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$extension] ?? null;

        return $this->servir($request, $regime->justificatif_fichier, "justificatif-regime-{$regime->id}.{$extension}", $mime);
    }

    /** Un export n'est téléchargeable que par celui qui l'a demandé, tant que son fichier n'a pas expiré. */
    public function fichierDeLExport(Export $export): Response
    {
        abort_if($export->utilisateur_id !== $this->personnel()->id, 404);
        abort_if($export->statut !== StatutExport::Termine || $export->fichier === null, 404);

        return $this->documents->servir(
            $export->fichier,
            $export->nom_fichier ?? "export-{$export->id}.{$export->format}",
            $export->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            enLigne: false,
        );
    }

    /** Rapport joint à un incident : la consultation est journalisée. */
    public function rapportIncident(Request $request, Incident $incident, Journal $journal): Response
    {
        $this->autoriser($incident, 'vie_internat.gerer');
        abort_if($incident->rapport_fichier === null, 404);
        $journal->consigner('incident.consultation_rapport', $incident);

        return $this->servir($request, $incident->rapport_fichier, "rapport-incident-{$incident->id}.pdf", 'application/pdf');
    }

    /** Document utile, vu par celui qui le publie. */
    public function fichierDuDocument(Request $request, Document $document): Response
    {
        $this->autoriser($document, 'documents.gerer');

        return $this->servir($request, $document->fichier, $document->nom_original, $document->mime);
    }

    /** Modèle CSV du référentiel : ses exemples sont au genre de l'internat du gestionnaire, puisque ce sont ses lignes qu'il va saisir. */
    public function modeleImport(ImportReferentielCsv $import): Response
    {
        $ids = $this->perimetre()->internatIds($this->personnel());
        $genre = $ids !== null && count($ids) === 1 ? Internat::find($ids[0])?->genre : null;

        return response($import->modele($genre ?? Genre::Fille), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="referentiel-internat-modele.csv"',
        ]);
    }

    public function rapportImport(ImportReferentiel $import, ImportReferentielCsv $service): Response
    {
        $this->autoriser($import, 'referentiel.gerer');

        return response($service->rapportErreurs($import), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rapport-import-'.$import->id.'.csv"',
        ]);
    }

    private function servir(Request $request, string $chemin, string $nom, ?string $mime): Response
    {
        return $this->documents->servir($chemin, $nom, $mime, enLigne: ! $request->boolean('telecharger'));
    }
}
