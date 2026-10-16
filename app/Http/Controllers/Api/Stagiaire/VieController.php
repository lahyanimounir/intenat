<?php

namespace App\Http\Controllers\Api\Stagiaire;

use App\Domain\Catering\Restauration;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Domain\Residence\VieInternat;
use App\Domain\Shared\Documents;
use App\Enums\DomaineReclamation;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Http\Controllers\Api\PaiementController;
use App\Http\Controllers\Controller;
use App\Models\DemandeSortie;
use App\Models\Document;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\Reclamation;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vie du stagiaire à l'internat : paiements, hébergement, sorties,
 * réclamations, repas, régimes et documents utiles.
 */
class VieController extends Controller
{
    // -------------------------------------------------------------- Paiements

    public function paiements(): JsonResponse
    {
        $demande = $this->stagiaire()->demandeCourante();

        return response()->json($demande
            ? $demande->paiements()->get()->map(fn (Paiement $p) => PaiementController::paiement($p))
            : []);
    }

    public function deposerRecu(Request $request, Paiement $paiement, SuiviPaiements $suivi): JsonResponse
    {
        $this->exigerProprietaire($paiement);
        $request->validate(['fichier' => ['required', 'file']]);

        return response()->json(PaiementController::paiement($suivi->deposerRecu($paiement, $request->file('fichier'))));
    }

    public function recu(Paiement $paiement, Documents $documents): Response
    {
        $this->exigerProprietaire($paiement);
        abort_if($paiement->recu_fichier === null, 404);

        return $documents->servir($paiement->recu_fichier, $paiement->recu_nom_original ?? 'recu', $paiement->recu_mime);
    }

    public function autorisation(Paiement $paiement, DocumentsAdministratifs $documents): Response
    {
        $this->exigerProprietaire($paiement);
        abort_if($paiement->date_autorisation === null, 404);

        return $documents->autorisationDePaiement($paiement);
    }

    // ------------------------------------------------------------ Hébergement

    public function hebergement(): JsonResponse
    {
        $affectation = $this->stagiaire()->affectationActive();

        if ($affectation === null) {
            return response()->json(['affectation' => null]);
        }

        $affectation->load('mouvements');
        $chambre = $affectation->lit->chambre;

        return response()->json([
            'affectation' => [
                'id' => $affectation->id,
                'internat' => $chambre->batiment->internat->nom,
                'batiment' => $chambre->batiment->nom,
                'chambre' => $chambre->numero,
                'etage' => $chambre->etage,
                'type_chambre' => self::enum($chambre->type_chambre),
                'equipements_chambre' => $chambre->equipements,
                'lit' => $affectation->lit->numero,
                'date_debut' => $affectation->date_debut->toDateString(),
                'arrivee_enregistree' => $affectation->arriveeEnregistree(),
            ],
            'mouvements' => $affectation->mouvements->map(fn ($m) => [
                'type' => self::enum($m->type), 'date_mouvement' => $m->date_mouvement,
            ]),
        ]);
    }

    public function attestation(DocumentsAdministratifs $documents): Response
    {
        $affectation = $this->stagiaire()->affectationActive();
        abort_if($affectation === null, 404);

        return $documents->attestationAffectation($affectation);
    }

    // ---------------------------------------------------------------- Sorties

    public function sorties(): JsonResponse
    {
        $stagiaire = $this->stagiaire();

        return response()->json(DemandeSortie::whereHas('affectation.demande', fn ($q) => $q->where('stagiaire_id', $stagiaire->id))
            ->latest('date_depart')->limit(50)->get()->map(fn (DemandeSortie $s) => [
                'id' => $s->id, 'date_depart' => $s->date_depart, 'date_retour' => $s->date_retour, 'motif' => $s->motif,
                'statut' => self::enum($s->statut), 'motif_refus' => $s->motif_refus, 'date_traitement' => $s->date_traitement,
            ]));
    }

    public function deposerSortie(Request $request, VieInternat $vie): JsonResponse
    {
        $donnees = $request->validate([
            'date_depart' => ['required', 'date', 'after_or_equal:now'],
            'date_retour' => ['required', 'date', 'after:date_depart'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return response()->json(['id' => $vie->deposerSortie($this->stagiaire(), $donnees)->id], 201);
    }

    // ----------------------------------------------------------- Réclamations

    public function reclamations(): JsonResponse
    {
        return response()->json([
            'reclamations' => Reclamation::where('stagiaire_id', $this->stagiaire()->id)->latest('date_creation')->limit(50)->get()
                ->map(fn (Reclamation $r) => [
                    'id' => $r->id, 'domaine' => self::enum($r->domaine), 'description' => $r->description,
                    'statut' => self::enum($r->statut), 'reponse' => $r->reponse,
                    'date_creation' => $r->date_creation, 'date_traitement' => $r->date_traitement,
                ]),
            'domaines' => DomaineReclamation::options(),
        ]);
    }

    public function deposerReclamation(Request $request, VieInternat $vie): JsonResponse
    {
        $donnees = $request->validate([
            'domaine' => ['required', Rule::enum(DomaineReclamation::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $reclamation = $vie->deposerReclamation($this->stagiaire(), DomaineReclamation::from($donnees['domaine']), $donnees['description']);

        return response()->json(['id' => $reclamation->id], 201);
    }

    // ------------------------------------------------------------------ Repas

    /** Menus validés des prochains jours, avec l'état de réservation du stagiaire. */
    public function menus(Restauration $restauration): JsonResponse
    {
        $stagiaire = $this->stagiaire();
        $affectation = $stagiaire->affectationActive();

        if ($affectation === null) {
            return response()->json(['eligible' => false, 'motif' => 'La réservation des repas est réservée aux résidents hébergés.', 'jours' => []]);
        }

        $reservations = ReservationRepas::where('stagiaire_id', $stagiaire->id)->get()->keyBy('menu_id');

        $menus = Menu::where('internat_id', $affectation->internatId())
            ->where('statut', StatutMenu::Valide->value)
            ->whereBetween('date_repas', [now()->toDateString(), now()->addDays(14)->toDateString()])
            ->with('service')
            ->orderBy('date_repas')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")
            ->get();

        return response()->json([
            'eligible' => true,
            'jours' => $menus->groupBy(fn (Menu $m) => $m->date_repas->toDateString())->map(fn ($duJour, string $date) => [
                'date' => $date,
                'repas' => $duJour->map(function (Menu $m) use ($reservations, $restauration, $stagiaire) {
                    $reservation = $reservations->get($m->id);
                    $reserve = $reservation?->statut === StatutReservation::Reserve;

                    return [
                        'menu_id' => $m->id,
                        'type_repas' => self::enum($m->type_repas),
                        'description' => $m->description,
                        'limite' => $restauration->limiteDeReservation($m),
                        'reservation' => $reservation ? ['id' => $reservation->id, 'statut' => self::enum($reservation->statut)] : null,
                        'modifiable' => $m->service?->effectif_confirme === null && now()->lessThanOrEqualTo($restauration->limiteDeReservation($m)),
                        'motif_blocage' => $reserve ? null : $restauration->motifIneligibilite($stagiaire, $m),
                    ];
                })->values(),
            ])->values(),
        ]);
    }

    public function reserver(Menu $menu, Restauration $restauration): JsonResponse
    {
        $reservation = $restauration->reserver($this->stagiaire(), $menu);

        return response()->json(['id' => $reservation->id, 'statut' => self::enum($reservation->statut)], 201);
    }

    public function annulerReservation(ReservationRepas $reservation, Restauration $restauration): JsonResponse
    {
        abort_if($reservation->stagiaire_id !== $this->stagiaire()->id, 404);

        return response()->json(['statut' => self::enum($restauration->annuler($reservation)->statut)]);
    }

    // ---------------------------------------------------------------- Régimes

    public function regimes(): JsonResponse
    {
        return response()->json([
            'regimes' => RegimeAlimentaire::where('stagiaire_id', $this->stagiaire()->id)->get()->map(fn (RegimeAlimentaire $r) => [
                'id' => $r->id, 'type' => self::enum($r->type), 'precision' => $r->precision_regime,
                'justificatif' => $r->justificatif_fichier !== null,
            ]),
            'types' => TypeRegime::options(),
        ]);
    }

    public function declarerRegime(Request $request, Documents $documents): JsonResponse
    {
        $stagiaire = $this->stagiaire();

        $donnees = $request->validate([
            'type' => ['required', Rule::enum(TypeRegime::class), Rule::unique('regime_alimentaire', 'type')->where('stagiaire_id', $stagiaire->id)],
            'precision' => ['nullable', 'string', 'max:255'],
            'justificatif' => ['required_unless:type,restriction_religieuse', 'nullable', 'file'],
        ], [
            'type.unique' => 'Ce régime est déjà déclaré.',
            'justificatif.required_unless' => 'Un justificatif est requis pour une allergie ou une restriction médicale.',
        ]);

        $chemin = $request->hasFile('justificatif')
            ? $documents->enregistrer(
                $request->file('justificatif'),
                "regimes/{$stagiaire->id}",
                config('internat.documents.extensions'),
                (int) config('internat.documents.taille_max_ko'),
            )['chemin']
            : null;

        $regime = RegimeAlimentaire::create([
            'stagiaire_id' => $stagiaire->id,
            'type' => $donnees['type'],
            'precision_regime' => $donnees['precision'] ?? null,
            'justificatif_fichier' => $chemin,
        ]);

        return response()->json(['id' => $regime->id], 201);
    }

    public function retirerRegime(RegimeAlimentaire $regime, Documents $documents): JsonResponse
    {
        abort_if($regime->stagiaire_id !== $this->stagiaire()->id, 404);
        $documents->supprimer($regime->justificatif_fichier);
        $regime->delete();

        return response()->json(['message' => 'Régime retiré.']);
    }

    // -------------------------------------------------------------- Documents

    public function documents(): JsonResponse
    {
        $internat = $this->stagiaire()->internat();

        return response()->json($internat
            ? Document::where('internat_id', $internat->id)->latest('date_publication')->get()->map(fn (Document $d) => [
                'id' => $d->id, 'titre' => $d->titre, 'type' => self::enum($d->type), 'date_publication' => $d->date_publication,
            ])
            : []);
    }

    public function document(Document $document, Documents $documents): Response
    {
        abort_if($document->internat_id !== $this->stagiaire()->internat()?->id, 404);

        return $documents->servir($document->fichier, $document->nom_original, $document->mime);
    }

    private function exigerProprietaire(Paiement $paiement): void
    {
        abort_if($paiement->demande->stagiaire_id !== $this->stagiaire()->id, 404);
    }
}
