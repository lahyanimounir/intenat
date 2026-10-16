<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Applications\Instruction;
use App\Domain\Audit\Journal;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Http\Controllers\Controller;
use App\Http\Resources\DemandeResource;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\PieceJointe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instruction des dossiers par le personnel. Toutes les lectures sont bornées
 * au périmètre ; un brouillon reste privé au stagiaire tant qu'il n'est pas déposé.
 */
class DossierController extends Controller
{
    public function __construct(
        private readonly Instruction $instruction,
        private readonly DossierStagiaire $dossiers,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $requete = Demande::visiblesPar($this->personnel())
            ->where('demande.statut', '!=', StatutDemande::Brouillon->value)
            ->with('stagiaire', 'campagne.internat')
            ->when($request->filled('campagne_id'), fn ($q) => $q->where('campagne_id', $request->integer('campagne_id')))
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut')->toString())))
            ->when($request->filled('internat_id'), fn ($q) => $q->whereHas('campagne', fn ($c) => $c->where('internat_id', $request->integer('internat_id'))))
            ->when($request->query('dossier_physique') === 'attendu', fn ($q) => $q->where('dossier_physique_depose', false))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->where(fn ($s) => $s->where('numero_dossier', 'like', $terme)
                    ->orWhereHas('stagiaire', fn ($st) => $st->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('cef', 'like', $terme)));
            });

        $this->trier($requete, $request, [
            'date_soumission' => 'date_soumission', 'numero_dossier' => 'numero_dossier', 'statut' => 'statut',
        ], 'date_soumission', 'desc');
        $requete->orderByDesc('demande.id');

        return DemandeResource::collection($this->paginer($requete, $request));
    }

    public function show(Demande $demande, AffectationLits $affectations): JsonResponse
    {
        $this->consulter($demande);
        $demande->load('stagiaire.etablissement', 'campagne.internat.etablissement', 'instructeur', 'paiements', 'affectationActive.lit.chambre');

        return response()->json([
            'demande' => new DemandeResource($demande),
            'pieces' => $this->dossiers->piecesAttendues($demande)->map(fn (array $p) => [
                'type' => $p['type']->only(['id', 'libelle', 'obligatoire', 'formats_acceptes']),
                'requise' => $p['requise'],
                'raison' => $p['raison'],
                'piece' => $p['piece'] ? self::piece($p['piece']) : null,
            ]),
            'historique' => HistoriqueDemande::where('demande_id', $demande->id)
                ->with('utilisateur:id,nom,prenom')->orderByDesc('date_heure')->orderByDesc('id')->get()
                ->map(fn (HistoriqueDemande $h) => [
                    'id' => $h->id,
                    'ancien_statut' => $h->ancien_statut ? StatutDemande::from($h->ancien_statut)->libelle() : null,
                    'nouveau_statut' => StatutDemande::from($h->nouveau_statut)->libelle(),
                    'auteur' => $h->automatique ? 'Application' : ($h->utilisateur?->nomComplet() ?? 'Stagiaire'),
                    'motif' => $h->motif,
                    'date_heure' => $h->date_heure,
                ]),
            'paiements' => $demande->paiements->map(fn ($p) => PaiementController::paiement($p)),
            'affectation' => $demande->affectationActive ? [
                'id' => $demande->affectationActive->id,
                'chambre' => $demande->affectationActive->lit->chambre->numero,
                'lit' => $demande->affectationActive->lit->numero,
                'date_debut' => $demande->affectationActive->date_debut->toDateString(),
            ] : null,
            'obstacles_occupation' => $demande->statut === StatutDemande::Confirme && ! $demande->occupationAutorisee()
                ? $affectations->obstaclesOccupation($demande) : [],
        ]);
    }

    /** Diffuse une pièce justificative après contrôle du périmètre. */
    public function fichierPiece(PieceJointe $piece, Documents $documents, Journal $journal): Response
    {
        $this->consulter($piece->demande);
        $journal->consigner('piece.consultation', $piece);

        return $documents->servir($piece->fichier, $piece->nom_original, $piece->mime);
    }

    public function controlerPiece(Request $request, PieceJointe $piece): JsonResponse
    {
        $this->autoriser($piece->demande, 'demandes.instruire');

        $donnees = $request->validate([
            'statut' => ['required', Rule::in([StatutPiece::Acceptee->value, StatutPiece::ARemplacer->value])],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        $piece = $this->instruction->controlerPiece($piece, StatutPiece::from($donnees['statut']), $donnees['commentaire'] ?? null, $this->personnel());

        return response()->json(self::piece($piece));
    }

    public function decider(Request $request, Demande $demande, string $decision): DemandeResource
    {
        $this->autoriser($demande, 'demandes.instruire');
        $gestionnaire = $this->personnel();

        $motif = in_array($decision, ['rectification', 'rejet'], true)
            ? $request->validate(['motif' => ['required', 'string', 'min:10', 'max:1000']])['motif']
            : null;

        $demande = match ($decision) {
            'complet' => $this->instruction->declarerComplet($demande, $gestionnaire),
            'rectification' => $this->instruction->renvoyerPourRectification($demande, $motif, $gestionnaire),
            'rejet' => $this->instruction->rejeter($demande, $motif, $gestionnaire),
            default => abort(404),
        };

        return new DemandeResource($demande->load('stagiaire', 'campagne.internat'));
    }

    /** Le gestionnaire atteste la réception et le contrôle du dossier papier. */
    public function dossierPhysique(Demande $demande, Journal $journal): DemandeResource
    {
        $this->autoriser($demande, 'hebergement.gerer');

        if (! in_array($demande->statut, [StatutDemande::Admis, StatutDemande::Confirme], true)) {
            throw new RegleMetierException('Le dossier physique ne concerne que les candidats admis.', 'non_admis');
        }

        if ($demande->dossier_physique_depose) {
            throw RegleMetierException::conflit('Le dossier physique est déjà enregistré.');
        }

        $demande->forceFill([
            'dossier_physique_depose' => true,
            'date_depot_physique' => now(),
            'dossier_physique_valide_par' => $this->personnel()->id,
        ])->save();

        $journal->consigner('demande.dossier_physique', $demande);

        return new DemandeResource($demande->load('stagiaire', 'campagne.internat'));
    }

    public function autoriserOccupation(Demande $demande, AffectationLits $affectations): DemandeResource
    {
        $this->autoriser($demande, 'hebergement.gerer');

        return new DemandeResource($affectations->autoriserOccupation($demande, $this->personnel())->load('stagiaire', 'campagne.internat'));
    }

    public function accuse(Demande $demande, DocumentsAdministratifs $documents): Response
    {
        $this->consulter($demande);
        abort_if($demande->numero_dossier === null, 404);

        return $documents->accuseDeReception($demande);
    }

    private function consulter(Demande $demande): void
    {
        $this->autoriser($demande, 'demandes.consulter');
        abort_if($demande->statut === StatutDemande::Brouillon, 404);
    }

    /** @return array<string, mixed> */
    public static function piece(PieceJointe $piece): array
    {
        return [
            'id' => $piece->id,
            'type_piece_id' => $piece->type_piece_id,
            'nom_original' => $piece->nom_original,
            'mime' => $piece->mime,
            'taille' => $piece->taille,
            'statut' => self::enum($piece->statut),
            'commentaire' => $piece->commentaire,
            'date_depot' => $piece->date_depot,
            'date_controle' => $piece->date_controle,
        ];
    }
}
