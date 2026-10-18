<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Journal;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Domain\Shared\Documents;
use App\Enums\StatutPaiement;
use App\Http\Controllers\Controller;
use App\Models\Paiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suivi des paiements côté personnel : le chef de pôle consulte, le
 * gestionnaire contrôle les reçus. Aucun paiement en ligne.
 */
class PaiementController extends Controller
{
    public function __construct(private readonly SuiviPaiements $paiements) {}

    public function index(Request $request): JsonResponse
    {
        $requete = Paiement::visiblesPar($this->personnel())
            ->with('demande.stagiaire', 'demande.campagne.internat', 'autorisateur:id,nom,prenom', 'validateur:id,nom,prenom')
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut')->toString())))
            ->when($request->filled('internat_id'), fn ($q) => $q->whereHas('demande.campagne', fn ($c) => $c->where('internat_id', $request->integer('internat_id'))))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->whereHas('demande', fn ($d) => $d->where('numero_dossier', 'like', $terme)
                    ->orWhereHas('stagiaire', fn ($s) => $s->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('cef', 'like', $terme)));
            })
            ->orderByDesc('id');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (Paiement $p) => self::paiement($p) + [
            'stagiaire' => $p->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'numero_dossier' => $p->demande->numero_dossier,
            'internat' => $p->demande->campagne->internat->nom,
        ]);

        return response()->json($page);
    }

    public function controler(Request $request, Paiement $paiement): JsonResponse
    {
        $this->autoriser($paiement, 'paiements.controler');

        $donnees = $request->validate([
            'decision' => ['required', Rule::in(['valider', 'refuser'])],
            'motif' => ['required_if:decision,refuser', 'nullable', 'string', 'min:5', 'max:500'],
        ]);

        $paiement = $donnees['decision'] === 'valider'
            ? $this->paiements->validerRecu($paiement, $this->personnel())
            : $this->paiements->refuserRecu($paiement, $this->personnel(), $donnees['motif']);

        return response()->json(self::paiement($paiement));
    }

    public function recu(Paiement $paiement, Documents $documents, Journal $journal): Response
    {
        $this->autoriser($paiement, 'paiements.consulter');
        abort_if($paiement->recu_fichier === null, 404);
        $journal->consigner('paiement.consultation_recu', $paiement);

        return $documents->servir($paiement->recu_fichier, $paiement->recu_nom_original ?? 'recu', $paiement->recu_mime);
    }

    public function autorisation(Paiement $paiement, DocumentsAdministratifs $documents): Response
    {
        $this->autoriser($paiement, 'paiements.consulter');

        return $documents->autorisationDePaiement($paiement);
    }

    /** @return array<string, mixed> */
    public static function paiement(Paiement $p): array
    {
        return [
            'id' => $p->id,
            'demande_id' => $p->demande_id,
            'periode' => $p->periode,
            'genere_auto' => $p->genere_auto,
            'montant' => $p->montant,
            'statut' => self::enum($p->statut),
            'date_autorisation' => $p->date_autorisation,
            'date_depot' => $p->date_depot,
            'date_validation' => $p->date_validation,
            'recu_depose' => $p->recu_fichier !== null,
            'motif_refus' => $p->motif_refus,
        ];
    }
}
