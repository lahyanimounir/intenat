<?php

namespace App\Http\Controllers\Api\Stagiaire;

use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Audit\Journal;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\RaisonDesistement;
use App\Enums\StatutDemande;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutPiece;
use App\Enums\StatutReservation;
use App\Http\Controllers\Api\DossierController as DossierPersonnelController;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Controller;
use App\Http\Resources\CampagneResource;
use App\Http\Resources\DemandeResource;
use App\Http\Resources\StagiaireResource;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\NotificationInterne;
use App\Models\PieceJointe;
use App\Models\ReservationRepas;
use App\Models\TypePiece;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace du stagiaire : profil, préinscription, suivi du dossier, décision.
 * Un stagiaire n'accède qu'à ses propres données : chaque dossier est
 * recherché à partir de son identifiant de session, jamais d'un paramètre libre.
 */
class DossierController extends Controller
{
    public function __construct(private readonly DossierStagiaire $dossiers) {}

    public function tableauDeBord(): JsonResponse
    {
        $stagiaire = $this->stagiaire();
        $demande = $stagiaire->demandeCourante();
        $affectation = $stagiaire->affectationActive();

        $manquantes = $demande && $demande->estModifiableParLeStagiaire()
            ? $this->dossiers->piecesAttendues($demande)
                ->filter(fn (array $p) => $p['requise'] && ($p['piece'] === null || $p['piece']->statut === StatutPiece::ARemplacer))
                ->map(fn (array $p) => $p['type']->libelle)->values()
            : collect();

        return response()->json([
            'profil_complet' => $stagiaire->genre !== null && $stagiaire->niveau_formation !== null,
            'campagne_ouverte' => ($c = $this->dossiers->campagneOuverte($stagiaire)) ? new CampagneResource($c->load('internat')) : null,
            'demande' => $demande ? new DemandeResource($demande) : null,
            'pieces_manquantes' => $manquantes,
            'hebergement' => $affectation ? [
                'internat' => $affectation->lit->chambre->batiment->internat->nom,
                'batiment' => $affectation->lit->chambre->batiment->nom,
                'chambre' => $affectation->lit->chambre->numero,
                'lit' => $affectation->lit->numero,
                'date_debut' => $affectation->date_debut->toDateString(),
            ] : null,
            'paiements_a_regler' => $demande
                ? $demande->paiements()->whereIn('statut', [StatutPaiement::Autorise->value, StatutPaiement::Refuse->value])->count() : 0,
            'repas_reserves' => ReservationRepas::where('stagiaire_id', $stagiaire->id)->where('statut', StatutReservation::Reserve->value)
                ->whereHas('menu', fn ($q) => $q->where('statut', StatutMenu::Valide->value)->whereDate('date_repas', '>=', now()->toDateString()))->count(),
            'notifications' => NotificationInterne::where('stagiaire_id', $stagiaire->id)->where('lue', false)
                ->latest('date_envoi')->limit(5)->get(['id', 'type', 'titre', 'message', 'lien', 'date_envoi']),
        ]);
    }

    public function profil(): JsonResponse
    {
        $stagiaire = $this->stagiaire()->load('etablissement');

        return response()->json([
            'stagiaire' => new StagiaireResource($stagiaire),
            'genre_verrouille' => $stagiaire->genreVerrouille(),
            'referentiels' => ['genres' => Genre::options(), 'niveaux' => NiveauFormation::options()],
        ]);
    }

    /** Le stagiaire complète ce que MyWay ne fournit pas : genre et niveau de formation. */
    public function majProfil(Request $request, Journal $journal): JsonResponse
    {
        $stagiaire = $this->stagiaire();

        $donnees = $request->validate([
            'genre' => ['required', Rule::enum(Genre::class)],
            'niveau_formation' => ['required', Rule::enum(NiveauFormation::class)],
            'tel' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ().-]{6,30}$/'],
        ], ['tel.regex' => 'Numéro de téléphone invalide.']);

        if ($stagiaire->genreVerrouille()
            && ($stagiaire->genre?->value !== $donnees['genre'] || $stagiaire->niveau_formation?->value !== $donnees['niveau_formation'])) {
            throw new RegleMetierException(
                'Le genre et le niveau de formation ne sont plus modifiables après le dépôt du dossier.',
                'profil_verrouille',
            );
        }

        // Changer de genre change d'internat : un brouillon ouvert pour l'autre internat est abandonné.
        if ($stagiaire->genre !== null && $stagiaire->genre->value !== $donnees['genre']) {
            $stagiaire->demandes()->where('statut', StatutDemande::Brouillon->value)->get()->each(function (Demande $brouillon) {
                $brouillon->pieces->each(fn (PieceJointe $p) => app(Documents::class)->supprimer($p->fichier));
                $brouillon->pieces()->delete();
                $brouillon->delete();
            });
        }

        $stagiaire->fill($donnees);

        // Ce que le stagiaire renseigne lui-même reste distinct de ce qui a été vérifié auprès de MyWay.
        $stagiaire->marquerDeclare(array_merge(['genre', 'niveau_formation'], $stagiaire->isDirty('tel') ? ['tel'] : []));
        $stagiaire->save();
        $journal->consigner('stagiaire.profil', $stagiaire, details: ['genre' => $donnees['genre'], 'niveau_formation' => $donnees['niveau_formation']]);

        return $this->profil();
    }

    public function show(): JsonResponse
    {
        $stagiaire = $this->stagiaire();
        $demande = $stagiaire->demandeCourante();

        if ($demande === null) {
            return response()->json([
                'demande' => null,
                'campagne_ouverte' => ($c = $this->dossiers->campagneOuverte($stagiaire)) ? new CampagneResource($c->load('internat')) : null,
                // Distingue « profil à compléter » de « aucune campagne ouverte » : ce ne sont pas les mêmes suites à donner.
                'profil_complet' => $stagiaire->genre !== null && $stagiaire->niveau_formation !== null,
            ]);
        }

        $demande->load('stagiaire.etablissement', 'campagne.internat.etablissement', 'desistement');

        return response()->json([
            'demande' => new DemandeResource($demande),
            'modifiable' => $demande->estModifiableParLeStagiaire(),
            'pieces' => $this->dossiers->piecesAttendues($demande)->map(fn (array $p) => [
                'type' => $p['type']->only(['id', 'libelle', 'obligatoire', 'formats_acceptes']),
                'requise' => $p['requise'],
                'raison' => $p['raison'],
                'piece' => $p['piece'] ? DossierPersonnelController::piece($p['piece']) : null,
            ]),
            'historique' => HistoriqueDemande::where('demande_id', $demande->id)->orderBy('date_heure')->orderBy('id')->get()
                ->map(fn (HistoriqueDemande $h) => [
                    'statut' => StatutDemande::from($h->nouveau_statut)->libelle(),
                    'date_heure' => $h->date_heure,
                    // Le motif n'est montré au stagiaire que pour les décisions qui le concernent directement.
                    'motif' => in_array($h->nouveau_statut, ['retour_rectification', 'rejete'], true) ? $h->motif : null,
                ]),
            'paiements' => $demande->paiements()->get()->map(fn ($p) => PaiementController::paiement($p)),
            'referentiels' => ['raisons_desistement' => RaisonDesistement::options()],
        ]);
    }

    public function ouvrir(): JsonResponse
    {
        $demande = $this->dossiers->ouvrirBrouillon($this->stagiaire());

        return response()->json(new DemandeResource($demande->load('campagne.internat')), 201);
    }

    public function enregistrer(Request $request): DemandeResource
    {
        $donnees = $request->validate([
            'adresse_complete' => ['nullable', 'string', 'max:500'],
            'eloigne_ou_rural' => ['sometimes', 'boolean'],
            'demuni' => ['sometimes', 'boolean'],
            'orphelin_ou_divorce' => ['sometimes', 'boolean'],
            'besoins_specifiques' => ['sometimes', 'boolean'],
            'precisions_besoins' => ['nullable', 'string', 'max:500'],
        ]);

        return new DemandeResource($this->dossiers->enregistrer($this->demande(), $donnees));
    }

    public function deposerPiece(Request $request, TypePiece $typePiece): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file']]);
        $piece = $this->dossiers->deposerPiece($this->demande(), $typePiece, $request->file('fichier'));

        return response()->json(DossierPersonnelController::piece($piece), 201);
    }

    public function retirerPiece(PieceJointe $piece): JsonResponse
    {
        $this->dossiers->retirerPiece($this->demande(), $piece);

        return response()->json(['message' => 'Pièce retirée.']);
    }

    public function fichierPiece(PieceJointe $piece, Documents $documents): Response
    {
        abort_if($piece->demande_id !== $this->demande()->id, 404);

        return $documents->servir($piece->fichier, $piece->nom_original, $piece->mime);
    }

    public function soumettre(): DemandeResource
    {
        return new DemandeResource($this->dossiers->soumettre($this->demande())->load('campagne.internat'));
    }

    public function accuse(DocumentsAdministratifs $documents): Response
    {
        $demande = $this->demande();
        abort_if($demande->numero_dossier === null, 404);

        return $documents->accuseDeReception($demande);
    }

    public function desister(Request $request, ConfirmationAdmission $confirmation): DemandeResource
    {
        $donnees = $request->validate([
            'raison' => ['required', Rule::enum(RaisonDesistement::class)],
            'explication' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        return new DemandeResource(
            $confirmation->desister($this->demande(), RaisonDesistement::from($donnees['raison']), $donnees['explication'])
        );
    }

    private function demande(): Demande
    {
        $demande = $this->stagiaire()->demandeCourante();
        abort_if($demande === null, 404, 'Aucun dossier.');

        return $demande;
    }
}
