<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Accommodation\ImportReferentielCsv;
use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\Genre;
use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutLit;
use App\Enums\TypeChambre;
use App\Enums\TypeMouvement;
use App\Http\Controllers\Controller;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\ImportReferentiel;
use App\Models\Internat;
use App\Models\Lit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Référentiel des lits : Internat → Bâtiment → Étage → Chambre → Lit.
 * L'internat cible d'une écriture est toujours résolu côté serveur.
 */
class ReferentielController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    /** Internat sur lequel l'utilisateur agit : le sien, ou celui demandé s'il est dans son périmètre. */
    private function internat(Request $request): Internat
    {
        $utilisateur = $this->personnel();
        $ids = $this->perimetre()->internatIds($utilisateur);
        $demande = $request->integer('internat_id') ?: null;

        if ($demande === null) {
            if ($ids !== null && count($ids) === 1) {
                $demande = $ids[0];
            } else {
                throw new RegleMetierException('Choisissez l\'internat concerné.', 'internat_requis');
            }
        }

        $this->perimetre()->exigerInternat($utilisateur, $demande);

        return Internat::with('etablissement')->findOrFail($demande);
    }

    /** Plan d'occupation : bâtiments, étages, chambres et lits d'un internat. */
    public function plan(Request $request): JsonResponse
    {
        $internat = $this->internat($request);

        $batiments = Batiment::where('internat_id', $internat->id)->orderBy('code')
            ->with(['chambres' => fn ($q) => $q->orderBy('etage')->orderBy('numero'),
                'chambres.lits' => fn ($q) => $q->orderBy('numero'),
                'chambres.lits.affectationActive.demande.stagiaire:id,nom,prenom,cef',
                'chambres.lits.affectationActive.mouvements'])
            ->get();

        $totaux = ['lits' => 0, 'disponibles' => 0, 'occupes' => 0, 'reserves' => 0, 'hors_service' => 0, 'en_maintenance' => 0];
        $cles = ['disponible' => 'disponibles', 'occupe' => 'occupes', 'reserve' => 'reserves', 'hors_service' => 'hors_service', 'en_maintenance' => 'en_maintenance'];

        foreach ($batiments as $batiment) {
            foreach ($batiment->chambres as $chambre) {
                foreach ($chambre->lits as $lit) {
                    $totaux['lits']++;
                    $totaux[$cles[self::etatLit($lit, $chambre)]]++;
                }
            }
        }

        // L'identité des occupants n'est montrée qu'aux rôles chargés de l'hébergement.
        $nominatif = $this->personnel()->can('hebergement.consulter');

        $plan = $batiments->map(fn (Batiment $b) => [
            'id' => $b->id, 'code' => $b->code, 'nom' => $b->nom, 'nombre_etages' => $b->nombre_etages,
            'statut' => self::enum($b->statut), 'equipements_communs' => $b->equipements_communs,
            'etages' => $b->chambres->groupBy('etage')->map(fn ($chambres, $etage) => [
                'etage' => (int) $etage,
                'chambres' => $chambres->map(fn (Chambre $c) => [
                    'id' => $c->id, 'numero' => $c->numero, 'type_chambre' => self::enum($c->type_chambre),
                    'capacite' => $c->capacite, 'statut' => self::enum($c->statut), 'equipements' => $c->equipements,
                    'lits' => $c->lits->map(function (Lit $l) use ($c, $nominatif) {
                        $occupant = $nominatif ? $l->affectationActive?->demande->stagiaire : null;

                        return [
                            'id' => $l->id, 'numero' => $l->numero, 'statut' => self::enum($l->statut),
                            'etat' => self::etatLit($l, $c), 'affectation_id' => $l->affectationActive?->id,
                            'occupant' => $occupant ? ['nom_complet' => $occupant->nomComplet(), 'cef' => $occupant->cef] : null,
                        ];
                    })->values(),
                ])->values(),
            ])->values(),
        ]);

        return response()->json([
            'internat' => ['id' => $internat->id, 'nom' => $internat->nom, 'genre' => self::enum($internat->genre)],
            'totaux' => $totaux,
            'batiments' => $plan,
        ]);
    }

    /** Liste paginée des lits, filtrable par bâtiment, chambre, statut et occupation. */
    public function lits(Request $request): JsonResponse
    {
        $requete = Lit::visiblesPar($this->personnel())
            ->with('chambre.batiment.internat', 'affectationActive.demande.stagiaire:id,nom,prenom,cef', 'affectationActive.mouvements')
            ->when($request->filled('internat_id'), fn ($q) => $q->whereHas('chambre.batiment', fn ($b) => $b->where('internat_id', $request->integer('internat_id'))))
            ->when($request->filled('batiment_id'), fn ($q) => $q->whereHas('chambre', fn ($c) => $c->where('batiment_id', $request->integer('batiment_id'))))
            ->when($request->filled('chambre_id'), fn ($q) => $q->where('chambre_id', $request->integer('chambre_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->when($request->filled('type_chambre'), fn ($q) => $q->whereHas('chambre', fn ($c) => $c->where('type_chambre', $request->string('type_chambre')->toString())))
            ->when($request->query('affectable') === '1', fn ($q) => $q->where('statut', StatutLit::Disponible->value)
                ->whereHas('chambre', fn ($c) => $c->where('statut', '!=', StatutChambre::EnMaintenance->value)
                    ->whereHas('batiment', fn ($b) => $b->where('statut', StatutBatiment::Ouvert->value))))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->whereHas('chambre', fn ($c) => $c->where('numero', 'like', $terme));
            })
            ->orderBy('chambre_id')->orderBy('numero');

        $nominatif = $this->personnel()->can('hebergement.consulter');
        $page = $this->paginer($requete, $request, 50);
        $page->getCollection()->transform(fn (Lit $l) => [
            'id' => $l->id, 'numero' => $l->numero, 'statut' => self::enum($l->statut), 'etat' => self::etatLit($l, $l->chambre),
            'chambre' => [
                'id' => $l->chambre->id, 'numero' => $l->chambre->numero, 'etage' => $l->chambre->etage,
                'type_chambre' => self::enum($l->chambre->type_chambre), 'statut' => self::enum($l->chambre->statut),
            ],
            'batiment' => $l->chambre->batiment->only(['id', 'code', 'nom']),
            'internat' => $l->chambre->batiment->internat->only(['id', 'nom']),
            'affectation_id' => $l->affectationActive?->id,
            'occupant' => $nominatif && $l->affectationActive ? [
                'nom_complet' => $l->affectationActive->demande->stagiaire->nomComplet(),
                'cef' => $l->affectationActive->demande->stagiaire->cef,
            ] : null,
        ]);

        return response()->json($page);
    }

    /** Historique des occupants d'un lit : aucune période passée n'est effacée. */
    public function historiqueLit(Lit $lit): JsonResponse
    {
        $this->autoriser($lit, 'referentiel.consulter');
        abort_unless($this->personnel()->can('hebergement.consulter'), 403);

        return response()->json($lit->affectations()->with('demande.stagiaire:id,nom,prenom,cef', 'affecteur:id,nom,prenom')
            ->orderByDesc('date_debut')->orderByDesc('id')->get()->map(fn ($a) => [
                'id' => $a->id, 'stagiaire' => $a->demande->stagiaire->nomComplet(), 'cef' => $a->demande->stagiaire->cef,
                'date_debut' => $a->date_debut->toDateString(), 'date_fin' => $a->date_fin?->toDateString(),
                'affecte_par' => $a->affecteur->nomComplet(),
            ]));
    }

    public function batiments(Request $request): JsonResponse
    {
        return response()->json(Batiment::visiblesPar($this->personnel())
            ->when($request->filled('internat_id'), fn ($q) => $q->where('internat_id', $request->integer('internat_id')))
            ->with('internat:id,nom,genre')->withCount('chambres')
            ->orderBy('internat_id')->orderBy('code')->get()
            ->map(fn (Batiment $b) => [
                'id' => $b->id, 'internat' => $b->internat->only(['id', 'nom']), 'code' => $b->code, 'nom' => $b->nom,
                'nombre_etages' => $b->nombre_etages, 'statut' => self::enum($b->statut),
                'equipements_communs' => $b->equipements_communs, 'chambres' => $b->chambres_count,
            ]));
    }

    public function enregistrerBatiment(Request $request, ?Batiment $batiment = null): JsonResponse
    {
        if ($batiment !== null) {
            $this->autoriser($batiment, 'referentiel.gerer');
            $internatId = $batiment->internat_id;
        } else {
            $internatId = $this->internat($request)->id;
        }

        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('batiment', 'code')->where('internat_id', $internatId)->ignore($batiment?->id)],
            'nom' => ['required', 'string', 'max:100'],
            'nombre_etages' => ['required', 'integer', 'min:0', 'max:30'],
            'statut' => ['required', Rule::enum(StatutBatiment::class)],
            'equipements_communs' => ['nullable', 'string', 'max:1000'],
        ]);

        $batiment ??= new Batiment(['internat_id' => $internatId]);
        $creation = ! $batiment->exists;

        if (! $creation && $batiment->chambres()->where('etage', '>', $donnees['nombre_etages'])->exists()) {
            throw new RegleMetierException('Des chambres existent au-dessus de ce nombre d\'étages.', 'etages_incoherents');
        }

        $batiment->fill($donnees)->save();
        $this->journal->consigner($creation ? 'batiment.creation' : 'batiment.modification', $batiment, details: $creation ? $donnees : $this->journal->differences($batiment));

        return response()->json($batiment, $creation ? 201 : 200);
    }

    public function enregistrerChambre(Request $request, ?Chambre $chambre = null): JsonResponse
    {
        if ($chambre !== null) {
            $this->autoriser($chambre, 'referentiel.gerer');
            $batiment = $chambre->batiment;
        } else {
            $batiment = Batiment::findOrFail($request->integer('batiment_id'));
            $this->autoriser($batiment, 'referentiel.gerer');
        }

        $donnees = $request->validate([
            'numero' => ['required', 'string', 'max:30', Rule::unique('chambre', 'numero')->where('batiment_id', $batiment->id)->ignore($chambre?->id)],
            'etage' => ['required', 'integer', 'min:0', 'max:'.$batiment->nombre_etages],
            'type_chambre' => ['required', Rule::enum(TypeChambre::class)],
            'capacite' => ['required', 'integer', 'min:1', 'max:20'],
            'equipements' => ['nullable', 'string', 'max:1000'],
        ], ['etage.max' => 'L\'étage dépasse le nombre d\'étages du bâtiment.']);

        $chambre ??= new Chambre(['batiment_id' => $batiment->id, 'statut' => StatutChambre::Disponible]);
        $creation = ! $chambre->exists;

        if (! $creation && $chambre->lits()->count() > $donnees['capacite']) {
            throw new RegleMetierException('La chambre compte déjà plus de lits que cette capacité.', 'capacite_insuffisante');
        }

        $chambre->fill($donnees)->save();
        $this->journal->consigner($creation ? 'chambre.creation' : 'chambre.modification', $chambre, details: $creation ? $donnees : $this->journal->differences($chambre));

        return response()->json($chambre, $creation ? 201 : 200);
    }

    public function ajouterLit(Request $request, Chambre $chambre): JsonResponse
    {
        $this->autoriser($chambre, 'referentiel.gerer');

        $donnees = $request->validate([
            'numero' => ['required', 'string', 'max:10', Rule::unique('lit', 'numero')->where('chambre_id', $chambre->id)],
        ]);

        if ($chambre->lits()->count() >= $chambre->capacite) {
            throw new RegleMetierException('La capacité de la chambre est atteinte : augmentez-la avant d\'ajouter un lit.', 'capacite_atteinte');
        }

        $lit = Lit::create(['chambre_id' => $chambre->id, 'numero' => $donnees['numero'], 'statut' => StatutLit::Disponible]);
        $this->journal->consigner('lit.creation', $lit, details: ['chambre' => $chambre->numero, 'numero' => $lit->numero]);

        return response()->json($lit, 201);
    }

    /** Met un lit hors service ou le remet en service. Un lit occupé ne peut pas être désactivé. */
    public function statutLit(Request $request, Lit $lit, AffectationLits $affectations): JsonResponse
    {
        $this->autoriser($lit, 'referentiel.gerer');
        $horsService = $request->validate(['hors_service' => ['required', 'boolean']])['hors_service'];

        if ($horsService && ($lit->statut === StatutLit::Occupe || $lit->affectationActive()->exists())) {
            throw new RegleMetierException('Ce lit est occupé : déplacez d\'abord son occupant.', 'lit_occupe');
        }

        $avant = $lit->statut->value;
        $lit->forceFill(['statut' => $horsService ? StatutLit::HorsService : StatutLit::Disponible])->save();
        $affectations->synchroniserChambre($lit->chambre);
        $this->journal->consigner('lit.statut', $lit, details: ['avant' => $avant, 'apres' => $lit->statut->value]);

        return response()->json(['id' => $lit->id, 'statut' => self::enum($lit->statut)]);
    }

    // ------------------------------------------------------------ Import CSV

    public function modeleImport(ImportReferentielCsv $import): Response
    {
        // Les exemples du modèle sont au genre de l'internat du gestionnaire : ce sont ses lignes qu'il va saisir.
        $ids = $this->perimetre()->internatIds($this->personnel());
        $genre = $ids !== null && count($ids) === 1 ? Internat::find($ids[0])?->genre : null;

        return response($import->modele($genre ?? Genre::Fille), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="referentiel-internat-modele.csv"',
        ]);
    }

    public function imports(Request $request): JsonResponse
    {
        $page = $this->paginer(
            ImportReferentiel::visiblesPar($this->personnel())->with('internat:id,nom', 'auteur:id,nom,prenom')->latest('id'),
            $request,
            15,
        );
        $page->getCollection()->transform(fn (ImportReferentiel $i) => self::import($i, false));

        return response()->json($page);
    }

    /** Étape 1 : analyse du fichier, sans rien écrire dans le référentiel. */
    public function analyserImport(Request $request, ImportReferentielCsv $import): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:2048']]);
        $internat = $this->internat($request);

        return response()->json(self::import($import->analyser($request->file('fichier'), $internat, $this->personnel())), 201);
    }

    /** Étape 2 : import transactionnel d'un fichier sans erreur. */
    public function confirmerImport(ImportReferentiel $import, ImportReferentielCsv $service): JsonResponse
    {
        $this->autoriser($import, 'referentiel.gerer');

        return response()->json(self::import($service->confirmer($import, $this->personnel())));
    }

    public function annulerImport(ImportReferentiel $import, ImportReferentielCsv $service): JsonResponse
    {
        $this->autoriser($import, 'referentiel.gerer');

        return response()->json(self::import($service->annuler($import)));
    }

    public function rapportImport(ImportReferentiel $import, ImportReferentielCsv $service): Response
    {
        $this->autoriser($import, 'referentiel.gerer');

        return response($service->rapportErreurs($import), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="rapport-import-'.$import->id.'.csv"',
        ]);
    }

    /**
     * État affiché d'un lit. « Réservé » n'est pas un statut stocké : c'est un
     * lit attribué dont l'occupant n'est pas encore arrivé.
     */
    public static function etatLit(Lit $lit, Chambre $chambre): string
    {
        if ($lit->statut === StatutLit::HorsService) {
            return 'hors_service';
        }

        if ($lit->affectationActive !== null) {
            $arrive = $lit->affectationActive->mouvements->contains(fn ($m) => $m->type === TypeMouvement::Arrivee);

            return $arrive ? 'occupe' : 'reserve';
        }

        return $chambre->statut === StatutChambre::EnMaintenance ? 'en_maintenance' : 'disponible';
    }

    /** @return array<string, mixed> */
    private static function import(ImportReferentiel $i, bool $detail = true): array
    {
        $rapport = $i->rapport ?? [];

        return [
            'id' => $i->id,
            'internat' => $i->relationLoaded('internat') ? $i->internat->only(['id', 'nom']) : ['id' => $i->internat_id],
            'nom_original' => $i->nom_original,
            'statut' => self::enum($i->statut),
            'total_lignes' => $i->total_lignes,
            'lignes_valides' => $i->lignes_valides,
            'lignes_erreur' => $i->lignes_erreur,
            'lignes_existantes' => $rapport['lignes_existantes'] ?? 0,
            // Lignes du fichier qui décrivent l'autre internat de l'établissement : signalées, jamais importées ici.
            'lignes_hors_internat' => $rapport['lignes_hors_internat'] ?? 0,
            'synthese' => $rapport['synthese'] ?? null,
            'resultat' => $rapport['resultat'] ?? null,
            'auteur' => $i->relationLoaded('auteur') ? $i->auteur->nomComplet() : null,
            'cree_le' => $i->created_at,
        ] + ($detail ? [
            'encodage' => $rapport['encodage'] ?? null,
            'separateur' => $rapport['separateur'] ?? null,
            'colonnes_ignorees' => $rapport['colonnes_ignorees'] ?? [],
            'erreurs_fichier' => $rapport['erreurs_fichier'] ?? [],
            'anomalies' => array_slice($rapport['anomalies'] ?? [], 0, 200),
            'apercu' => $rapport['apercu'] ?? [],
            'importable' => $i->lignes_erreur === 0 && ($rapport['erreurs_fichier'] ?? []) === [] && $i->lignes_valides > 0,
        ] : []);
    }
}
