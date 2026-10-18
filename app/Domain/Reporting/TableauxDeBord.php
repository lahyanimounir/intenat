<?php

namespace App\Domain\Reporting;

use App\Domain\Identity\Perimetre;
use App\Domain\Identity\Permissions;
use App\Enums\DomaineReclamation;
use App\Enums\RoleCode;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutLit;
use App\Enums\StatutMaintenance;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutReclamation;
use App\Enums\StatutReservation;
use App\Enums\StatutSortie;
use App\Enums\TypeMouvement;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\DemandeMaintenance;
use App\Models\DemandeSortie;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\JournalAudit;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\Reclamation;
use App\Models\Region;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Un tableau de bord par rôle : chacun répond aux tâches de ce rôle, et tous
 * les chiffres viennent de requêtes d'agrégation bornées au périmètre du compte.
 */
class TableauxDeBord
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly Indicateurs $indicateurs,
    ) {}

    /** @return array<string, mixed> */
    public function pour(Utilisateur $utilisateur): array
    {
        $ids = $this->perimetre->internatIds($utilisateur);

        return match ($utilisateur->roleCode()) {
            RoleCode::Admin => $this->administrateur(),
            RoleCode::Gestionnaire => $this->gestionnaire($utilisateur, $ids ?? []),
            RoleCode::ChefPole => $this->chefDePole($ids ?? []),
            RoleCode::Restauration => $this->restauration($ids ?? []),
            RoleCode::Directeur => $this->directeur($ids ?? []),
            RoleCode::DirRegionale => $this->directionRegionale($utilisateur, $ids ?? []),
            RoleCode::DirCentrale => $this->directionCentrale(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function administrateur(): array
    {
        $niveaux = array_filter(Permissions::niveauxDePerimetre());

        $sansPerimetre = Utilisateur::query()->with('roles')->where('actif', true)->get()
            ->filter(function (Utilisateur $u) use ($niveaux) {
                $colonne = $niveaux[$u->roleCode()?->value] ?? null;

                return $u->roleCode() === null
                    || ($colonne !== null && $u->{$colonne} === null
                        && ! ($u->roleCode() === RoleCode::Directeur && $u->etablissement_id !== null));
            })->count();

        $litsIncoherents = DB::table('lit')
            ->leftJoin('affectation', fn ($j) => $j->on('affectation.lit_id', '=', 'lit.id')->whereNull('affectation.date_fin'))
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('lit.statut', StatutLit::Occupe->value)->whereNull('affectation.id'))
                ->orWhere(fn ($s) => $s->where('lit.statut', '!=', StatutLit::Occupe->value)->whereNotNull('affectation.id')))
            ->count();

        $chambresSurchargees = DB::table('chambre')
            ->whereRaw('(SELECT COUNT(*) FROM lit WHERE lit.chambre_id = chambre.id) > chambre.capacite')->count();

        $alertes = array_values(array_filter([
            $sansPerimetre > 0 ? ['niveau' => 'attention', 'message' => "{$sansPerimetre} compte(s) actif(s) sans rôle ou sans périmètre : ils n'ont accès à aucune donnée.", 'lien' => '/administration/utilisateurs'] : null,
            ($n = Internat::whereDoesntHave('gestionnaires', fn ($q) => $q->where('actif', true))->count()) > 0
                ? ['niveau' => 'attention', 'message' => "{$n} internat(s) sans gestionnaire actif.", 'lien' => '/administration/organisation'] : null,
            ($n = Etablissement::whereDoesntHave('internats')->count()) > 0
                ? ['niveau' => 'information', 'message' => "{$n} établissement(s) sans internat rattaché.", 'lien' => '/administration/organisation'] : null,
            $litsIncoherents > 0 ? ['niveau' => 'critique', 'message' => "{$litsIncoherents} lit(s) dont le statut contredit l'affectation en cours.", 'lien' => null] : null,
            $chambresSurchargees > 0 ? ['niveau' => 'critique', 'message' => "{$chambresSurchargees} chambre(s) comptant plus de lits que leur capacité.", 'lien' => null] : null,
            ($n = DB::table('failed_jobs')->count()) > 0
                ? ['niveau' => 'attention', 'message' => "{$n} tâche(s) en échec dans la file d'attente.", 'lien' => null] : null,
        ]));

        return [
            'type' => 'administrateur',
            'comptes' => [
                'actifs' => Utilisateur::where('actif', true)->count(),
                'inactifs' => Utilisateur::where('actif', false)->count(),
                'par_role' => DB::table('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->groupBy('roles.name')->selectRaw('roles.name as role, COUNT(*) as total')
                    ->pluck('total', 'role')->map(fn ($t) => (int) $t)->all(),
            ],
            'organisation' => [
                'regions' => Region::count(),
                'complexes' => DB::table('complexe')->count(),
                'etablissements' => Etablissement::count(),
                'internats' => Internat::count(),
            ],
            'parametres_a_valider' => Parametre::where('a_valider', true)->count(),
            'alertes' => $alertes,
            'audit_recent' => JournalAudit::with('utilisateur:id,nom,prenom')->latest('id')->limit(8)->get()
                ->map(fn (JournalAudit $j) => [
                    'id' => $j->id, 'action' => $j->action, 'entite' => $j->entite, 'entite_id' => $j->entite_id,
                    'date_heure' => $j->date_heure, 'auteur' => $j->utilisateur?->nomComplet() ?? ($j->stagiaire_id ? 'Stagiaire' : 'Système'),
                ])->all(),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function gestionnaire(Utilisateur $utilisateur, array $ids): array
    {
        $dossiers = fn () => Demande::query()->whereHas('campagne', fn ($q) => $q->whereIn('internat_id', $ids)->whereNull('archivee_le'));
        $sejours = fn () => Affectation::actives()->whereHas('demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids));
        $occupation = $this->indicateurs->occupationParInternat($ids)->first();

        $parBatiment = DB::table('lit')
            ->join('chambre', 'chambre.id', '=', 'lit.chambre_id')
            ->join('batiment', 'batiment.id', '=', 'chambre.batiment_id')
            ->whereIn('batiment.internat_id', $ids)
            ->groupBy('batiment.id', 'batiment.nom')
            ->orderBy('batiment.nom')
            ->selectRaw('batiment.nom as batiment, COUNT(*) as lits')
            ->selectRaw('SUM(lit.statut = ?) as occupes', [StatutLit::Occupe->value])
            ->selectRaw('SUM(lit.statut = ?) as hors_service', [StatutLit::HorsService->value])
            ->get()
            ->map(fn ($l) => [
                'batiment' => $l->batiment, 'lits' => (int) $l->lits, 'occupes' => (int) $l->occupes,
                'hors_service' => (int) $l->hors_service,
                'disponibles' => (int) $l->lits - (int) $l->occupes - (int) $l->hors_service,
            ])->all();

        return [
            'type' => 'gestionnaire',
            'internat' => $utilisateur->internat?->only(['id', 'nom', 'genre']),
            'instruction' => [
                'a_instruire' => $dossiers()->where('statut', StatutDemande::Recu->value)->count(),
                'en_rectification' => $dossiers()->where('statut', StatutDemande::RetourRectification->value)->count(),
                'complets' => $dossiers()->where('statut', StatutDemande::Complet->value)->count(),
            ],
            'hebergement' => [
                'residents' => $sejours()->count(),
                'lits' => $occupation->lits ?? 0,
                'occupes' => $occupation->occupes ?? 0,
                'disponibles' => $occupation->disponibles ?? 0,
                'hors_service' => $occupation->hors_service ?? 0,
                'taux_occupation' => $occupation->taux ?? 0.0,
                'a_heberger' => $dossiers()->where('statut', StatutDemande::Confirme->value)->whereDoesntHave('affectationActive')->count(),
                'arrivees_attendues' => $sejours()->whereDoesntHave('mouvements', fn ($q) => $q->where('type', TypeMouvement::Arrivee->value))->count(),
                'departs_a_enregistrer' => $sejours()->whereHas('demande', fn ($q) => $q->where('statut', StatutDemande::Desiste->value))->count(),
            ],
            'paiements' => [
                'recus_a_controler' => Paiement::where('statut', StatutPaiement::RecuDepose->value)
                    ->whereHas('demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
                // Mensualités à payer dont le justificatif n'a pas encore été déposé.
                'recus_manquants' => Paiement::whereIn('statut', [StatutPaiement::EnAttente->value, StatutPaiement::Autorise->value])
                    ->whereHas('demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
            ],
            'quotidien' => [
                'sorties_a_traiter' => DemandeSortie::where('statut', StatutSortie::EnAttente->value)
                    ->whereHas('affectation.demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
                'absences_non_autorisees_7j' => Absence::where('autorisee', false)->whereDate('date_absence', '>=', now()->subDays(6)->toDateString())
                    ->whereHas('affectation.demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
                'maintenances_ouvertes' => DemandeMaintenance::where('statut', '!=', StatutMaintenance::Resolue->value)
                    ->whereHas('chambre.batiment', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
                'reclamations_ouvertes' => Reclamation::whereIn('internat_id', $ids)->where('domaine', DomaineReclamation::Hebergement->value)
                    ->where('statut', '!=', StatutReclamation::Traitee->value)->count(),
            ],
            'occupation_par_batiment' => $parBatiment,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function chefDePole(array $ids): array
    {
        $campagnes = Campagne::whereIn('internat_id', $ids)->whereNull('archivee_le')
            ->with('internat:id,nom,genre')->orderByDesc('annee')->orderBy('internat_id')->get();

        $parCampagne = DB::table('demande')->whereIn('campagne_id', $campagnes->pluck('id'))
            ->groupBy('campagne_id', 'statut')->selectRaw('campagne_id, statut, COUNT(*) as total')->get()
            ->groupBy('campagne_id');

        $lignes = $campagnes->map(function (Campagne $c) use ($parCampagne) {
            $statuts = ($parCampagne[$c->id] ?? collect())->pluck('total', 'statut')->map(fn ($t) => (int) $t)->all();
            $synthese = $this->indicateurs->syntheseAdmissions($statuts);
            $instruits = $synthese['deposes'] - $synthese['a_instruire'] - ($statuts[StatutDemande::RetourRectification->value] ?? 0);
            $occupees = ($statuts[StatutDemande::Admis->value] ?? 0) + ($statuts[StatutDemande::Confirme->value] ?? 0);

            return [
                'id' => $c->id, 'annee' => $c->annee, 'internat' => $c->internat->only(['id', 'nom', 'genre']),
                'statut' => ['valeur' => $c->statut->value, 'libelle' => $c->statut->libelle()], 'phase' => $c->phase(),
                'date_cloture' => $c->date_cloture->toDateString(), 'places' => $c->nombre_places,
                'places_libres' => $c->estDeliberee() ? max(0, $c->nombre_places - $occupees) : null,
                'progression_instruction' => $synthese['deposes'] > 0 ? round($instruits * 100 / $synthese['deposes']) : 0,
            ] + $synthese;
        });

        $deliberables = $campagnes->filter(fn (Campagne $c) => $c->statut === StatutCampagne::Cloturee && ! $c->estDeliberee())->count();

        return [
            'type' => 'chef_pole',
            'campagnes' => $lignes->values()->all(),
            'a_traiter' => [
                'deliberations' => $deliberables,
                'places_a_pourvoir' => $lignes->filter(fn ($l) => ($l['places_libres'] ?? 0) > 0 && $l['en_attente'] > 0)->count(),
                // Suivi seulement : mensualités non encore réglées (à payer ou justificatif rejeté).
                'mensualites_impayees' => Paiement::whereIn('statut', [StatutPaiement::EnAttente->value, StatutPaiement::Autorise->value, StatutPaiement::Refuse->value])
                    ->whereHas('demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
                'recours' => Reclamation::whereIn('internat_id', $ids)->where('domaine', DomaineReclamation::Selection->value)
                    ->where('statut', '!=', StatutReclamation::Traitee->value)->count(),
            ],
            'occupation' => $this->lignesOccupation($ids),
            'desistements' => $this->indicateurs->raisonsDesistement($ids),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function restauration(array $ids): array
    {
        $jour = fn (string $date) => Menu::whereIn('internat_id', $ids)->whereDate('date_repas', $date)
            ->with('service', 'internat:id,nom,genre')
            ->withCount(['reservations as reserves' => fn ($q) => $q->where('statut', '!=', StatutReservation::Annule->value)])
            ->orderBy('internat_id')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")
            ->get()
            ->map(fn (Menu $m) => [
                'id' => $m->id, 'internat' => $m->internat->only(['id', 'nom', 'genre']),
                'type_repas' => ['valeur' => $m->type_repas->value, 'libelle' => $m->type_repas->libelle()],
                'statut' => ['valeur' => $m->statut->value, 'libelle' => $m->statut->libelle()],
                'description' => $m->description, 'reserves' => (int) $m->reserves,
                'confirmes' => $m->service?->effectif_confirme, 'servis' => $m->service?->effectif_servi,
                'conforme' => $m->service?->conforme,
            ])->all();

        $debutMois = now()->startOfMonth()->toDateString();

        $mois = DB::table('service_repas')
            ->join('menu', 'menu.id', '=', 'service_repas.menu_id')
            ->whereIn('menu.internat_id', $ids)
            ->whereDate('service_repas.date_service', '>=', $debutMois)
            ->selectRaw('COALESCE(SUM(effectif_reserve), 0) as reserves, COALESCE(SUM(effectif_confirme), 0) as confirmes')
            ->selectRaw('COALESCE(SUM(effectif_servi), 0) as servis, SUM(conforme = 0) as non_conformes, COUNT(effectif_servi) as services_clos')
            ->first();

        return [
            'type' => 'restauration',
            'aujourdhui' => $jour(now()->toDateString()),
            'demain' => $jour(now()->addDay()->toDateString()),
            'a_traiter' => [
                'menus_a_valider' => Menu::whereIn('internat_id', $ids)->where('statut', StatutMenu::Propose->value)
                    ->whereDate('date_repas', '>=', now()->toDateString())->count(),
                'effectifs_a_confirmer' => Menu::whereIn('internat_id', $ids)->where('statut', StatutMenu::Valide->value)
                    ->whereDate('date_repas', now()->addDay()->toDateString())
                    ->whereDoesntHave('service', fn ($q) => $q->whereNotNull('effectif_confirme'))->count(),
                'services_a_clore' => Menu::whereIn('internat_id', $ids)->whereDate('date_repas', '<=', now()->toDateString())
                    ->whereHas('service', fn ($q) => $q->whereNotNull('effectif_confirme')->whereNull('date_cloture_service'))->count(),
                'reclamations' => Reclamation::whereIn('internat_id', $ids)->where('domaine', DomaineReclamation::Restauration->value)
                    ->where('statut', '!=', StatutReclamation::Traitee->value)->count(),
            ],
            'residents_heberges' => Affectation::actives()->whereHas('demande.campagne', fn ($q) => $q->whereIn('internat_id', $ids))->count(),
            'mois' => [
                'reserves' => (int) $mois->reserves, 'confirmes' => (int) $mois->confirmes, 'servis' => (int) $mois->servis,
                'services_clos' => (int) $mois->services_clos, 'non_conformes' => (int) $mois->non_conformes,
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function directeur(array $ids): array
    {
        return [
            'type' => 'directeur',
            'etablissements' => $this->lignesParEtablissement($ids),
            'tendance' => $this->indicateurs->tendanceOccupation($ids),
            'desistements' => $this->indicateurs->raisonsDesistement($ids),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function directionRegionale(Utilisateur $utilisateur, array $ids): array
    {
        return [
            'type' => 'direction_regionale',
            'region' => $utilisateur->region?->only(['id', 'code', 'nom']),
            'etablissements' => $this->lignesParEtablissement($ids),
            'tendance' => $this->indicateurs->tendanceOccupation($ids),
        ];
    }

    /** @return array<string, mixed> */
    private function directionCentrale(): array
    {
        $occupation = $this->indicateurs->occupationParInternat(null);
        $dossiers = $this->indicateurs->dossiersParInternat(null);
        $internats = Internat::with('etablissement.complexe.province:id,region_id')->get(['id', 'etablissement_id']);

        $regions = Region::orderBy('nom')->get()->map(function (Region $region) use ($internats, $occupation, $dossiers) {
            $ids = $internats->filter(fn (Internat $i) => $i->etablissement?->complexe?->province?->region_id === $region->id)->pluck('id');
            $lits = $ids->sum(fn ($id) => $occupation[$id]->lits ?? 0);
            $horsService = $ids->sum(fn ($id) => $occupation[$id]->hors_service ?? 0);
            $occupes = $ids->sum(fn ($id) => $occupation[$id]->occupes ?? 0);
            $statuts = [];

            foreach ($ids as $id) {
                foreach ($dossiers[$id] ?? [] as $statut => $total) {
                    $statuts[$statut] = ($statuts[$statut] ?? 0) + $total;
                }
            }

            return [
                'id' => $region->id, 'code' => $region->code, 'nom' => $region->nom,
                'internats' => $ids->count(), 'lits' => $lits, 'occupes' => $occupes,
                'taux' => ($lits - $horsService) > 0 ? round($occupes * 100 / ($lits - $horsService), 1) : 0.0,
            ] + $this->indicateurs->syntheseAdmissions($statuts);
        });

        $historique = DB::table('demande')
            ->join('campagne', 'campagne.id', '=', 'demande.campagne_id')
            ->where('demande.statut', '!=', StatutDemande::Brouillon->value)
            ->groupBy('campagne.annee')->orderBy('campagne.annee')
            ->selectRaw('campagne.annee as annee, COUNT(*) as deposes')
            ->selectRaw('SUM(demande.statut IN (?, ?, ?)) as admis', [
                StatutDemande::Admis->value, StatutDemande::Confirme->value, StatutDemande::SejourTermine->value,
            ])
            ->get()->map(fn ($l) => ['annee' => $l->annee, 'deposes' => (int) $l->deposes, 'admis' => (int) $l->admis])->all();

        return [
            'type' => 'direction_centrale',
            'regions' => $regions->all(),
            'national' => [
                'lits' => $regions->sum('lits'), 'occupes' => $regions->sum('occupes'),
                'deposes' => $regions->sum('deposes'), 'admis' => $regions->sum('admis'), 'en_attente' => $regions->sum('en_attente'),
            ],
            'historique' => $historique,
            'tendance' => $this->indicateurs->tendanceOccupation(null),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function lignesOccupation(array $ids): array
    {
        $occupation = $this->indicateurs->occupationParInternat($ids);

        return Internat::whereIn('id', $ids)->orderBy('nom')->get()->map(fn (Internat $i) => [
            'internat' => $i->only(['id', 'nom', 'genre']),
            'lits' => $occupation[$i->id]->lits ?? 0,
            'occupes' => $occupation[$i->id]->occupes ?? 0,
            'disponibles' => $occupation[$i->id]->disponibles ?? 0,
            'hors_service' => $occupation[$i->id]->hors_service ?? 0,
            'taux' => $occupation[$i->id]->taux ?? 0.0,
        ])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function lignesParEtablissement(array $ids): array
    {
        $occupation = $this->indicateurs->occupationParInternat($ids);
        $dossiers = $this->indicateurs->dossiersParInternat($ids);

        return Internat::whereIn('id', $ids)->with('etablissement')->get()
            ->groupBy('etablissement_id')
            ->map(function ($internats) use ($occupation, $dossiers) {
                $etablissement = $internats->first()->etablissement;
                $lits = $internats->sum(fn (Internat $i) => $occupation[$i->id]->lits ?? 0);
                $horsService = $internats->sum(fn (Internat $i) => $occupation[$i->id]->hors_service ?? 0);
                $occupes = $internats->sum(fn (Internat $i) => $occupation[$i->id]->occupes ?? 0);
                $statuts = [];

                foreach ($internats as $internat) {
                    foreach ($dossiers[$internat->id] ?? [] as $statut => $total) {
                        $statuts[$statut] = ($statuts[$statut] ?? 0) + $total;
                    }
                }

                return [
                    'id' => $etablissement->id, 'code_efp' => $etablissement->code_efp, 'nom_efp' => $etablissement->nom_efp,
                    'internats' => $internats->count(), 'lits' => $lits, 'occupes' => $occupes,
                    'disponibles' => $lits - $occupes - $horsService,
                    'taux' => ($lits - $horsService) > 0 ? round($occupes * 100 / ($lits - $horsService), 1) : 0.0,
                ] + $this->indicateurs->syntheseAdmissions($statuts);
            })
            ->sortBy('nom_efp')->values()->all();
    }
}
