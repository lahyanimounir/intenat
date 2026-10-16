<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Journal;
use App\Domain\Residence\VieInternat;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutMaintenance;
use App\Enums\StatutReclamation;
use App\Enums\StatutSortie;
use App\Enums\TypeDocument;
use App\Enums\TypeIncident;
use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\Chambre;
use App\Models\DemandeMaintenance;
use App\Models\DemandeSortie;
use App\Models\Document;
use App\Models\Incident;
use App\Models\Internat;
use App\Models\Reclamation;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vie quotidienne de l'internat, côté personnel : sorties, absences, incidents,
 * maintenance, réclamations et documents utiles.
 */
class VieInternatController extends Controller
{
    public function __construct(
        private readonly VieInternat $vie,
        private readonly Journal $journal,
    ) {}

    // ---------------------------------------------------------------- Sorties

    public function sorties(Request $request): JsonResponse
    {
        $requete = DemandeSortie::visiblesPar($this->personnel())
            ->with('affectation.demande.stagiaire', 'affectation.lit.chambre', 'traitant:id,nom,prenom')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->orderByRaw('statut = ? DESC', [StatutSortie::EnAttente->value])->orderByDesc('date_depart');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (DemandeSortie $s) => [
            'id' => $s->id,
            'stagiaire' => $s->affectation->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'chambre' => $s->affectation->lit->chambre->numero,
            'date_depart' => $s->date_depart, 'date_retour' => $s->date_retour, 'motif' => $s->motif,
            'statut' => self::enum($s->statut), 'motif_refus' => $s->motif_refus,
            'traitant' => $s->traitant?->nomComplet(), 'date_traitement' => $s->date_traitement,
        ]);

        return response()->json($page);
    }

    public function traiterSortie(Request $request, DemandeSortie $sortie): JsonResponse
    {
        $this->autoriser($sortie, 'vie_internat.gerer');

        $donnees = $request->validate([
            'decision' => ['required', Rule::in(['accepter', 'refuser'])],
            'motif_refus' => ['required_if:decision,refuser', 'nullable', 'string', 'min:5', 'max:500'],
        ]);

        $sortie = $this->vie->traiterSortie($sortie, $donnees['decision'] === 'accepter', $donnees['motif_refus'] ?? null, $this->personnel());

        return response()->json(['id' => $sortie->id, 'statut' => self::enum($sortie->statut)]);
    }

    // --------------------------------------------------------------- Absences

    /** Feuille de présence d'un jour : résidents de l'internat et absences déjà saisies. */
    public function feuilleAbsences(Request $request): JsonResponse
    {
        $internat = $this->internat($request);
        $date = Carbon::parse($request->validate(['date' => ['nullable', 'date', 'before_or_equal:today']])['date'] ?? now())->startOfDay();

        $residents = Affectation::query()
            ->whereDate('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
            ->whereHas('demande.campagne', fn ($q) => $q->where('internat_id', $internat->id))
            ->with('demande.stagiaire:id,nom,prenom,cef', 'lit.chambre')
            ->with(['absences' => fn ($q) => $q->whereDate('date_absence', $date)])
            ->with(['sorties' => fn ($q) => $q->where('statut', StatutSortie::Acceptee->value)
                ->whereDate('date_depart', '<=', $date)->whereDate('date_retour', '>=', $date)])
            ->get()
            ->sortBy(fn (Affectation $a) => [$a->lit->chambre->numero, $a->lit->numero])->values();

        return response()->json([
            'internat' => $internat->only(['id', 'nom']),
            'date' => $date->toDateString(),
            'residents' => $residents->map(fn (Affectation $a) => [
                'affectation_id' => $a->id,
                'stagiaire' => $a->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
                'chambre' => $a->lit->chambre->numero, 'lit' => $a->lit->numero,
                'sortie_acceptee' => $a->sorties->isNotEmpty(),
                'absence' => ($abs = $a->absences->first()) ? ['id' => $abs->id, 'autorisee' => $abs->autorisee] : null,
            ]),
        ]);
    }

    public function saisirAbsences(Request $request): JsonResponse
    {
        $internat = $this->internat($request);

        $donnees = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'affectation_ids' => ['required', 'array', 'min:1', 'max:500'],
            'affectation_ids.*' => ['integer', 'distinct'],
        ]);

        return response()->json($this->vie->saisirAbsences(
            $internat->id, Carbon::parse($donnees['date'])->startOfDay(), $donnees['affectation_ids'], $this->personnel(),
        ), 201);
    }

    public function absences(Request $request): JsonResponse
    {
        $requete = Absence::visiblesPar($this->personnel())
            ->with('affectation.demande.stagiaire:id,nom,prenom,cef', 'affectation.lit.chambre', 'auteur:id,nom,prenom')
            ->when($request->query('autorisee') === '0', fn ($q) => $q->where('autorisee', false))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('date_absence', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('date_absence', '<=', $request->date('au')))
            ->orderByDesc('date_absence')->orderByDesc('id');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (Absence $a) => [
            'id' => $a->id, 'date_absence' => $a->date_absence->toDateString(), 'autorisee' => $a->autorisee,
            'stagiaire' => $a->affectation->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'chambre' => $a->affectation->lit->chambre->numero, 'saisi_par' => $a->auteur->nomComplet(),
        ]);

        return response()->json($page);
    }

    /** Annule une absence saisie par erreur ; l'annulation est tracée. */
    public function supprimerAbsence(Absence $absence): JsonResponse
    {
        $this->autoriser($absence, 'vie_internat.gerer');
        $this->journal->consigner('absence.annulation', $absence, details: [
            'affectation_id' => $absence->affectation_id, 'date' => $absence->date_absence->toDateString(), 'autorisee' => $absence->autorisee,
        ]);
        $absence->delete();

        return response()->json(['message' => 'Absence annulée.']);
    }

    // -------------------------------------------------------------- Incidents

    public function incidents(Request $request): JsonResponse
    {
        $requete = Incident::visiblesPar($this->personnel())
            ->with('affectation.demande.stagiaire:id,nom,prenom,cef', 'affectation.lit.chambre', 'auteur:id,nom,prenom')
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->orderByDesc('date_incident');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (Incident $i) => [
            'id' => $i->id, 'type' => self::enum($i->type), 'description' => $i->description,
            'date_incident' => $i->date_incident, 'rapport' => $i->rapport_fichier !== null,
            'stagiaire' => $i->affectation->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'chambre' => $i->affectation->lit->chambre->numero, 'saisi_par' => $i->auteur->nomComplet(),
        ]);

        return response()->json($page);
    }

    public function enregistrerIncident(Request $request, Documents $documents): JsonResponse
    {
        $donnees = $request->validate([
            'affectation_id' => ['required', 'integer'],
            'type' => ['required', Rule::enum(TypeIncident::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'date_incident' => ['required', 'date', 'before_or_equal:now'],
            'rapport' => ['nullable', 'file'],
        ]);

        $affectation = Affectation::visiblesPar($this->personnel())->findOrFail($donnees['affectation_id']);
        $this->autoriser($affectation, 'vie_internat.gerer');

        $chemin = $request->hasFile('rapport')
            ? $documents->enregistrer($request->file('rapport'), "incidents/{$affectation->id}", ['pdf'], (int) config('internat.documents.taille_max_ko'))['chemin']
            : null;

        $incident = Incident::create([
            'affectation_id' => $affectation->id, 'type' => $donnees['type'], 'description' => $donnees['description'],
            'date_incident' => $donnees['date_incident'], 'rapport_fichier' => $chemin, 'saisi_par' => $this->personnel()->id,
        ]);

        $this->journal->consigner('incident.creation', $incident, details: ['type' => $donnees['type'], 'affectation_id' => $affectation->id]);

        return response()->json(['id' => $incident->id], 201);
    }

    public function rapportIncident(Incident $incident, Documents $documents): Response
    {
        $this->autoriser($incident, 'vie_internat.gerer');
        abort_if($incident->rapport_fichier === null, 404);
        $this->journal->consigner('incident.consultation_rapport', $incident);

        return $documents->servir($incident->rapport_fichier, "rapport-incident-{$incident->id}.pdf", 'application/pdf');
    }

    // ------------------------------------------------------------ Maintenance

    public function maintenances(Request $request): JsonResponse
    {
        $requete = DemandeMaintenance::visiblesPar($this->personnel())
            ->with('chambre.batiment', 'auteur:id,nom,prenom')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->orderByRaw('statut = ? ASC', [StatutMaintenance::Resolue->value])->orderByDesc('date_demande');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (DemandeMaintenance $m) => [
            'id' => $m->id, 'description' => $m->description, 'statut' => self::enum($m->statut),
            'date_demande' => $m->date_demande, 'bloque_chambre' => $m->bloque_chambre,
            'chambre' => ['id' => $m->chambre->id, 'numero' => $m->chambre->numero, 'batiment' => $m->chambre->batiment->nom],
            'saisi_par' => $m->auteur->nomComplet(), 'date_resolution' => $m->date_resolution, 'resolution' => $m->resolution,
        ]);

        return response()->json($page);
    }

    public function signalerMaintenance(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'chambre_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'bloque_chambre' => ['required', 'boolean'],
        ]);

        $chambre = Chambre::visiblesPar($this->personnel())->findOrFail($donnees['chambre_id']);
        $this->autoriser($chambre, 'vie_internat.gerer');

        $demande = $this->vie->signalerMaintenance($chambre, $donnees['description'], $donnees['bloque_chambre'], $this->personnel());

        return response()->json(['id' => $demande->id], 201);
    }

    public function avancerMaintenance(Request $request, DemandeMaintenance $maintenance): JsonResponse
    {
        $this->autoriser($maintenance, 'vie_internat.gerer');

        $donnees = $request->validate([
            'statut' => ['required', Rule::in([StatutMaintenance::EnCours->value, StatutMaintenance::Resolue->value])],
            'resolution' => ['required_if:statut,resolue', 'nullable', 'string', 'max:1000'],
        ]);

        $maintenance = $this->vie->avancerMaintenance($maintenance, StatutMaintenance::from($donnees['statut']), $donnees['resolution'] ?? null, $this->personnel());

        return response()->json(['id' => $maintenance->id, 'statut' => self::enum($maintenance->statut)]);
    }

    // ----------------------------------------------------------- Réclamations

    /** Réclamations du domaine de l'utilisateur : hébergement, restauration ou sélection. */
    public function reclamations(Request $request): JsonResponse
    {
        $utilisateur = $this->personnel();
        $domaine = VieInternat::domaineDuRole($utilisateur->roleCode());
        abort_if($domaine === null, 403);

        $requete = Reclamation::visiblesPar($utilisateur)->where('domaine', $domaine->value)
            ->with('stagiaire:id,nom,prenom,cef', 'internat:id,nom', 'traitant:id,nom,prenom')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->orderByRaw('statut = ? ASC', [StatutReclamation::Traitee->value])->orderByDesc('date_creation');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (Reclamation $r) => [
            'id' => $r->id, 'domaine' => self::enum($r->domaine), 'description' => $r->description,
            'statut' => self::enum($r->statut), 'reponse' => $r->reponse, 'date_creation' => $r->date_creation,
            'date_traitement' => $r->date_traitement, 'stagiaire' => $r->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'internat' => $r->internat->nom, 'traitant' => $r->traitant?->nomComplet(),
        ]);

        return response()->json(['domaine' => self::enum($domaine), 'reclamations' => $page]);
    }

    public function traiterReclamation(Request $request, Reclamation $reclamation): JsonResponse
    {
        $utilisateur = $this->personnel();
        $this->autoriser($reclamation, 'reclamations.traiter');
        abort_if($reclamation->domaine !== VieInternat::domaineDuRole($utilisateur->roleCode()), 403, 'Cette réclamation relève d\'un autre responsable.');

        $donnees = $request->validate([
            'statut' => ['required', Rule::in([StatutReclamation::EnCours->value, StatutReclamation::Traitee->value])],
            'reponse' => ['required_if:statut,traitee', 'nullable', 'string', 'min:5', 'max:2000'],
        ]);

        $reclamation = $this->vie->traiterReclamation($reclamation, StatutReclamation::from($donnees['statut']), $donnees['reponse'] ?? null, $utilisateur);

        return response()->json(['id' => $reclamation->id, 'statut' => self::enum($reclamation->statut)]);
    }

    // -------------------------------------------------------------- Documents

    public function documents(Request $request): JsonResponse
    {
        return response()->json([
            'documents' => Document::visiblesPar($this->personnel())->with('internat:id,nom', 'auteur:id,nom,prenom')
                ->when($request->filled('internat_id'), fn ($q) => $q->where('internat_id', $request->integer('internat_id')))
                ->latest('date_publication')->get()->map(fn (Document $d) => [
                    'id' => $d->id, 'titre' => $d->titre, 'type' => self::enum($d->type), 'internat' => $d->internat->only(['id', 'nom']),
                    'date_publication' => $d->date_publication, 'publie_par' => $d->auteur->nomComplet(), 'nom_original' => $d->nom_original,
                ]),
            'types' => TypeDocument::options(),
        ]);
    }

    public function publierDocument(Request $request, Documents $documents): JsonResponse
    {
        $internat = $this->internat($request);

        $donnees = $request->validate([
            'titre' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::enum(TypeDocument::class)],
            'fichier' => ['required', 'file'],
        ]);

        $stocke = $documents->enregistrer($request->file('fichier'), "documents/{$internat->id}", ['pdf'], (int) config('internat.documents.taille_max_ko'));

        $document = Document::create([
            'internat_id' => $internat->id, 'titre' => $donnees['titre'], 'type' => $donnees['type'],
            'fichier' => $stocke['chemin'], 'nom_original' => $stocke['nom_original'], 'mime' => $stocke['mime'],
            'date_publication' => now(), 'publie_par' => $this->personnel()->id,
        ]);

        $this->journal->consigner('document.publication', $document, details: ['titre' => $document->titre, 'internat_id' => $internat->id]);

        return response()->json(['id' => $document->id], 201);
    }

    public function fichierDocument(Document $document, Documents $documents): Response
    {
        $this->autoriser($document, 'documents.gerer');

        return $documents->servir($document->fichier, $document->nom_original, $document->mime);
    }

    public function supprimerDocument(Document $document, Documents $documents): JsonResponse
    {
        $this->autoriser($document, 'documents.gerer');
        $this->journal->consigner('document.suppression', $document, details: ['titre' => $document->titre]);
        $chemin = $document->fichier;
        $document->delete();
        $documents->supprimer($chemin);

        return response()->json(['message' => 'Document retiré.']);
    }

    /** Internat visé : celui de l'utilisateur s'il n'en a qu'un, sinon celui demandé dans son périmètre. */
    private function internat(Request $request): Internat
    {
        $utilisateur = $this->personnel();
        $ids = $this->perimetre()->internatIds($utilisateur);
        $demande = $request->integer('internat_id') ?: ($ids !== null && count($ids) === 1 ? $ids[0] : null);

        if ($demande === null) {
            throw new RegleMetierException('Choisissez l\'internat concerné.', 'internat_requis');
        }

        $this->perimetre()->exigerInternat($utilisateur, $demande);

        return Internat::findOrFail($demande);
    }
}
