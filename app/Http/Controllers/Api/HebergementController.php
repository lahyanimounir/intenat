<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Reporting\DocumentsAdministratifs;
use App\Enums\StatutDemande;
use App\Enums\StatutPaiement;
use App\Enums\TypeMouvement;
use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Demande;
use App\Models\Lit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hébergement : admis confirmés à loger, résidents, affectation des lits,
 * arrivées, changements de lit et fins de séjour.
 */
class HebergementController extends Controller
{
    public function __construct(private readonly AffectationLits $affectations) {}

    /** Admis confirmés sans lit : paiement et permission d'occuper côte à côte. */
    public function candidats(Request $request): JsonResponse
    {
        $requete = Demande::visiblesPar($this->personnel())
            ->where('statut', StatutDemande::Confirme->value)
            ->whereDoesntHave('affectationActive')
            ->with('stagiaire', 'campagne.internat', 'paiements')
            ->when($request->filled('internat_id'), fn ($q) => $q->whereHas('campagne', fn ($c) => $c->where('internat_id', $request->integer('internat_id'))))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->where(fn ($s) => $s->where('numero_dossier', 'like', $terme)
                    ->orWhereHas('stagiaire', fn ($st) => $st->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('cef', 'like', $terme)));
            })
            ->orderBy('date_confirmation');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(function (Demande $d) {
            $paiement = $d->paiements->first();

            return [
                'id' => $d->id,
                'numero_dossier' => $d->numero_dossier,
                'stagiaire' => $d->stagiaire->only(['id', 'nom', 'prenom', 'cef', 'nom_filiere']),
                'internat' => $d->campagne->internat->only(['id', 'nom']),
                'besoins_specifiques' => $d->besoins_specifiques,
                'precisions_besoins' => $d->precisions_besoins,
                'date_confirmation' => $d->date_confirmation,
                'paiement' => $paiement ? PaiementController::paiement($paiement) : null,
                'recu_valide' => $d->paiements->contains(fn ($p) => $p->statut === StatutPaiement::Valide),
                'occupation_autorisee' => $d->occupationAutorisee(),
                'occupation_sans_recu' => $d->occupation_sans_recu,
                'obstacles_occupation' => $d->occupationAutorisee() ? [] : $this->affectations->obstaclesOccupation($d),
            ];
        });

        return response()->json($page);
    }

    public function residents(Request $request): JsonResponse
    {
        $requete = Affectation::visiblesPar($this->personnel())
            ->with('demande.stagiaire', 'demande.campagne.internat', 'lit.chambre.batiment', 'mouvements')
            ->when($request->query('etat') === 'clos', fn ($q) => $q->whereNotNull('date_fin'), fn ($q) => $q->whereNull('date_fin'))
            ->when($request->filled('internat_id'), fn ($q) => $q->whereHas('demande.campagne', fn ($c) => $c->where('internat_id', $request->integer('internat_id'))))
            ->when($request->filled('batiment_id'), fn ($q) => $q->whereHas('lit.chambre', fn ($c) => $c->where('batiment_id', $request->integer('batiment_id'))))
            ->when($request->query('arrivee') === 'attendue', fn ($q) => $q->whereDoesntHave('mouvements', fn ($m) => $m->where('type', TypeMouvement::Arrivee->value)))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->whereHas('demande', fn ($d) => $d->where('numero_dossier', 'like', $terme)
                    ->orWhereHas('stagiaire', fn ($st) => $st->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('cef', 'like', $terme)));
            })
            ->orderByDesc('date_debut')->orderByDesc('id');

        $page = $this->paginer($requete, $request);
        $page->getCollection()->transform(fn (Affectation $a) => self::affectation($a));

        return response()->json($page);
    }

    public function show(Affectation $affectation): JsonResponse
    {
        $this->autoriser($affectation, 'hebergement.consulter');
        $affectation->load(
            'demande.stagiaire.etablissement', 'demande.campagne.internat', 'demande.paiements', 'lit.chambre.batiment',
            'mouvements.auteur:id,nom,prenom', 'sorties', 'absences', 'incidents',
        );

        return response()->json(self::affectation($affectation) + [
            'stagiaire_detail' => $affectation->demande->stagiaire->only(['email', 'tel', 'ville', 'nom_filiere']),
            'demande_statut' => self::enum($affectation->demande->statut),
            'mouvements' => $affectation->mouvements->map(fn ($m) => [
                'id' => $m->id, 'type' => self::enum($m->type), 'date_mouvement' => $m->date_mouvement,
                'motif' => $m->motif, 'auteur' => $m->auteur->nomComplet(),
            ]),
            'paiements' => $affectation->demande->paiements->map(fn ($p) => PaiementController::paiement($p)),
            'compteurs' => [
                'sorties' => $affectation->sorties->count(),
                'absences' => $affectation->absences->count(),
                'absences_non_autorisees' => $affectation->absences->where('autorisee', false)->count(),
                'incidents' => $affectation->incidents->count(),
            ],
            'historique_lits' => Affectation::where('demande_id', $affectation->demande_id)->with('lit.chambre')
                ->orderByDesc('date_debut')->orderByDesc('id')->get()->map(fn (Affectation $a) => [
                    'id' => $a->id, 'chambre' => $a->lit->chambre->numero, 'lit' => $a->lit->numero,
                    'date_debut' => $a->date_debut->toDateString(), 'date_fin' => $a->date_fin?->toDateString(),
                ]),
        ]);
    }

    public function affecter(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'demande_id' => ['required', 'integer'],
            'lit_id' => ['required', 'integer'],
            'date_debut' => ['required', 'date', 'after_or_equal:today'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        // Les deux lignes sont recherchées dans le périmètre : un identifiant étranger renvoie 404.
        $demande = Demande::visiblesPar($this->personnel())->findOrFail($donnees['demande_id']);
        $lit = Lit::visiblesPar($this->personnel())->findOrFail($donnees['lit_id']);
        $this->autoriser($demande, 'hebergement.gerer');

        $affectation = $this->affectations->affecter($demande, $lit, Carbon::parse($donnees['date_debut']), $this->personnel(), $donnees['motif'] ?? null);

        return response()->json(self::affectation($affectation->load('demande.stagiaire', 'demande.campagne.internat', 'lit.chambre.batiment', 'mouvements')), 201);
    }

    public function changerDeLit(Request $request, Affectation $affectation): JsonResponse
    {
        $this->autoriser($affectation, 'hebergement.gerer');

        $donnees = $request->validate([
            'lit_id' => ['required', 'integer'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $lit = Lit::visiblesPar($this->personnel())->findOrFail($donnees['lit_id']);
        $nouvelle = $this->affectations->changerDeLit($affectation, $lit, $donnees['motif'], $this->personnel());

        return response()->json(self::affectation($nouvelle->load('demande.stagiaire', 'demande.campagne.internat', 'lit.chambre.batiment', 'mouvements')), 201);
    }

    public function arrivee(Affectation $affectation): JsonResponse
    {
        $this->autoriser($affectation, 'hebergement.gerer');
        $this->affectations->enregistrerArrivee($affectation, $this->personnel());

        return response()->json(['message' => 'Arrivée enregistrée.']);
    }

    public function cloturer(Request $request, Affectation $affectation): JsonResponse
    {
        $this->autoriser($affectation, 'hebergement.gerer');

        $donnees = $request->validate([
            'type' => ['required', Rule::in([TypeMouvement::Depart->value, TypeMouvement::Exclusion->value, TypeMouvement::NonPresente->value])],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $this->affectations->cloturer($affectation, TypeMouvement::from($donnees['type']), $donnees['motif'], $this->personnel());

        return response()->json(['message' => 'Séjour clos, lit libéré.']);
    }

    public function attestation(Affectation $affectation, DocumentsAdministratifs $documents): Response
    {
        $this->autoriser($affectation, 'hebergement.consulter');

        return $documents->attestationAffectation($affectation);
    }

    /** @return array<string, mixed> */
    public static function affectation(Affectation $a): array
    {
        $arrivee = $a->mouvements->first(fn ($m) => $m->type === TypeMouvement::Arrivee);

        return [
            'id' => $a->id,
            'demande_id' => $a->demande_id,
            'numero_dossier' => $a->demande->numero_dossier,
            'stagiaire' => $a->demande->stagiaire->only(['id', 'nom', 'prenom', 'cef']),
            'internat' => $a->demande->campagne->internat->only(['id', 'nom']),
            'batiment' => $a->lit->chambre->batiment->only(['id', 'code', 'nom']),
            'chambre' => ['id' => $a->lit->chambre->id, 'numero' => $a->lit->chambre->numero, 'etage' => $a->lit->chambre->etage],
            'lit' => ['id' => $a->lit->id, 'numero' => $a->lit->numero],
            'date_debut' => $a->date_debut->toDateString(),
            'date_fin' => $a->date_fin?->toDateString(),
            'active' => $a->estActive(),
            'arrivee_le' => $arrivee?->date_mouvement,
            'desiste' => $a->demande->statut === StatutDemande::Desiste,
            'motif_exceptionnel' => $a->motif_exceptionnel,
        ];
    }
}
