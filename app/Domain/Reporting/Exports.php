<?php

namespace App\Domain\Reporting;

use App\Domain\Identity\Perimetre;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\ListeAdmission;
use App\Enums\StatutDemande;
use App\Enums\StatutExport;
use App\Enums\StatutReservation;
use App\Enums\TypeNotification;
use App\Jobs\GenererExport;
use App\Models\Affectation;
use App\Models\Campagne;
use App\Models\Chambre;
use App\Models\Demande;
use App\Models\Export;
use App\Models\Internat;
use App\Models\Menu;
use App\Models\Paiement;
use App\Models\Utilisateur;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Exports asynchrones (CSV pour tableur, PDF pour diffusion).
 *
 * Chaque jeu de données est construit avec les mêmes portées de périmètre que
 * les écrans : un utilisateur n'exporte jamais une ligne qu'il ne peut pas voir.
 * La permission exigée par un type est vérifiée à la demande ET à la génération.
 */
class Exports
{
    /** @var array<string, array{libelle: string, permission: string, campagne: bool}> */
    public const TYPES = [
        'residents' => ['libelle' => 'Liste des résidents', 'permission' => 'hebergement.consulter', 'campagne' => false],
        'occupation' => ['libelle' => 'Occupation des chambres', 'permission' => 'referentiel.consulter', 'campagne' => false],
        'dossiers' => ['libelle' => 'Dossiers de préinscription', 'permission' => 'demandes.consulter', 'campagne' => true],
        'liste_admis' => ['libelle' => 'Liste des admis', 'permission' => 'admissions.consulter', 'campagne' => true],
        'liste_attente' => ['libelle' => 'Liste d\'attente', 'permission' => 'admissions.consulter', 'campagne' => true],
        'paiements' => ['libelle' => 'Suivi des paiements', 'permission' => 'paiements.consulter', 'campagne' => false],
        'effectifs_repas' => ['libelle' => 'Effectifs de restauration', 'permission' => 'restauration.consulter', 'campagne' => false],
        'indicateurs' => ['libelle' => 'Indicateurs par établissement', 'permission' => 'pilotage.consulter', 'campagne' => false],
    ];

    public function __construct(
        private readonly Documents $documents,
        private readonly TableauxDeBord $tableaux,
        private readonly Notifieur $notifieur,
    ) {}

    /** @return list<array{type: string, libelle: string, campagne: bool}> */
    public function typesPour(Utilisateur $utilisateur): array
    {
        return collect(self::TYPES)
            ->filter(fn (array $t) => $utilisateur->can($t['permission']))
            ->map(fn (array $t, string $type) => ['type' => $type, 'libelle' => $t['libelle'], 'campagne' => $t['campagne']])
            ->values()->all();
    }

    /** @param array<string, mixed> $filtres */
    public function demander(Utilisateur $utilisateur, string $type, string $format, array $filtres): Export
    {
        $this->exigerDroit($utilisateur, $type);

        if (self::TYPES[$type]['campagne'] && in_array($type, ['liste_admis', 'liste_attente'], true) && empty($filtres['campagne_id'])) {
            throw new RegleMetierException('Choisissez la campagne à exporter.', 'campagne_requise');
        }

        $export = Export::create([
            'utilisateur_id' => $utilisateur->id,
            'type' => $type,
            'format' => $format,
            'filtres' => $filtres ?: null,
            'statut' => StatutExport::EnAttente,
        ]);

        GenererExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    public function generer(Export $export): Export
    {
        $utilisateur = $export->utilisateur;
        $export->forceFill(['statut' => StatutExport::EnCours])->save();

        try {
            // Les droits sont revérifiés : ils ont pu changer depuis la demande.
            $this->exigerDroit($utilisateur, $export->type);
            $jeu = $this->jeuDeDonnees($export->type, $utilisateur, $export->filtres ?? []);
            $titre = self::TYPES[$export->type]['libelle'];
            $reference = 'EXP-'.now()->format('Ymd').'-'.str_pad((string) $export->id, 6, '0', STR_PAD_LEFT);

            $contenu = $export->format === 'pdf'
                ? Pdf::loadView('pdf.tableau', [
                    'titre' => $titre, 'reference' => $reference, 'sousTitre' => $jeu['sous_titre'],
                    'colonnes' => $jeu['colonnes'], 'lignes' => $jeu['lignes'], 'auteur' => $utilisateur->nomComplet(),
                ])->setPaper('a4', count($jeu['colonnes']) > 6 ? 'landscape' : 'portrait')->output()
                : $this->csv($jeu['colonnes'], $jeu['lignes']);

            $chemin = "exports/{$utilisateur->id}/".Str::uuid().'.'.$export->format;
            $this->documents->disque()->put($chemin, $contenu);

            $export->forceFill([
                'statut' => StatutExport::Termine,
                'fichier' => $chemin,
                'nom_fichier' => Str::slug($titre).'-'.now()->format('Y-m-d').'.'.$export->format,
                'lignes' => count($jeu['lignes']),
                'termine_le' => now(),
                'expire_le' => now()->addDays(7),
            ])->save();

            $this->notifieur->versUtilisateur(
                $utilisateur,
                TypeNotification::Information,
                'Export prêt',
                "« {$titre} » est prêt au téléchargement ({$export->lignes} ligne(s)).",
                '/rapports',
                "export:{$export->id}",
                email: false,
            );
        } catch (\Throwable $e) {
            $export->forceFill([
                'statut' => StatutExport::Echec,
                'erreur' => $e instanceof RegleMetierException ? $e->getMessage() : 'Erreur interne lors de la génération.',
                'termine_le' => now(),
            ])->save();

            if (! $e instanceof RegleMetierException) {
                report($e);
            }
        }

        return $export;
    }

    public function purgerExpires(): int
    {
        $expires = Export::whereNotNull('fichier')->where('expire_le', '<', now())->get();

        foreach ($expires as $export) {
            $this->documents->supprimer($export->fichier);
            $export->forceFill(['fichier' => null])->save();
        }

        return $expires->count();
    }

    private function exigerDroit(Utilisateur $utilisateur, string $type): void
    {
        if (! isset(self::TYPES[$type]) || ! $utilisateur->can('exports.creer') || ! $utilisateur->can(self::TYPES[$type]['permission'])) {
            throw new RegleMetierException('Vous n\'êtes pas autorisé à produire cet export.', 'export_interdit', statutHttp: 403);
        }
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return array{sous_titre: string, colonnes: list<string>, lignes: list<list<string|int|float|null>>}
     */
    private function jeuDeDonnees(string $type, Utilisateur $u, array $filtres): array
    {
        $campagneId = isset($filtres['campagne_id']) ? (int) $filtres['campagne_id'] : null;
        $internatId = isset($filtres['internat_id']) ? (int) $filtres['internat_id'] : null;

        return match ($type) {
            'residents' => [
                'sous_titre' => 'Résidents hébergés au '.now()->translatedFormat('j F Y'),
                'colonnes' => ['Internat', 'Bâtiment', 'Chambre', 'Lit', 'CEF', 'Nom', 'Prénom', 'Filière', 'Depuis le'],
                'lignes' => Affectation::actives()->visiblesPar($u)
                    ->when($internatId, fn ($q) => $q->whereHas('demande.campagne', fn ($c) => $c->where('internat_id', $internatId)))
                    ->with('demande.stagiaire', 'lit.chambre.batiment.internat')->get()
                    ->sortBy(fn (Affectation $a) => [$a->lit->chambre->batiment->code, $a->lit->chambre->numero, $a->lit->numero])
                    ->map(fn (Affectation $a) => [
                        $a->lit->chambre->batiment->internat->nom, $a->lit->chambre->batiment->nom, $a->lit->chambre->numero,
                        $a->lit->numero, $a->demande->stagiaire->cef, $a->demande->stagiaire->nom, $a->demande->stagiaire->prenom,
                        $a->demande->stagiaire->nom_filiere, $a->date_debut->format('d/m/Y'),
                    ])->values()->all(),
            ],
            'occupation' => [
                'sous_titre' => 'État des chambres au '.now()->translatedFormat('j F Y'),
                'colonnes' => ['Internat', 'Bâtiment', 'Étage', 'Chambre', 'Type', 'Statut', 'Capacité', 'Lits', 'Occupés', 'Hors service'],
                'lignes' => Chambre::visiblesPar($u)
                    ->when($internatId, fn ($q) => $q->whereHas('batiment', fn ($b) => $b->where('internat_id', $internatId)))
                    ->with('batiment.internat')
                    ->withCount(['lits', 'lits as occupes' => fn ($q) => $q->where('statut', 'occupe'), 'lits as hs' => fn ($q) => $q->where('statut', 'hors_service')])
                    ->get()->sortBy(fn (Chambre $c) => [$c->batiment->internat->nom, $c->batiment->code, $c->etage, $c->numero])
                    ->map(fn (Chambre $c) => [
                        $c->batiment->internat->nom, $c->batiment->nom, $c->etage, $c->numero, $c->type_chambre->libelle(),
                        $c->statut->libelle(), $c->capacite, $c->lits_count, $c->occupes, $c->hs,
                    ])->values()->all(),
            ],
            'dossiers' => [
                'sous_titre' => 'Dossiers déposés',
                'colonnes' => ['N° dossier', 'Internat', 'Année', 'CEF', 'Nom', 'Prénom', 'Filière', 'Statut', 'Déposé le'],
                'lignes' => Demande::visiblesPar($u)->where('statut', '!=', StatutDemande::Brouillon->value)
                    ->when($campagneId, fn ($q) => $q->where('campagne_id', $campagneId))
                    ->when(! empty($filtres['statut']), fn ($q) => $q->where('statut', $filtres['statut']))
                    ->with('stagiaire', 'campagne.internat')->orderBy('date_soumission')->get()
                    ->map(fn (Demande $d) => [
                        $d->numero_dossier, $d->campagne->internat->nom, $d->campagne->annee, $d->stagiaire->cef,
                        $d->stagiaire->nom, $d->stagiaire->prenom, $d->stagiaire->nom_filiere, $d->statut->libelle(),
                        $d->date_soumission?->format('d/m/Y H:i'),
                    ])->all(),
            ],
            'liste_admis', 'liste_attente' => $this->listeAdmission($type, $u, (int) $campagneId),
            'paiements' => [
                'sous_titre' => 'Paiements des frais d\'internat',
                'colonnes' => ['N° dossier', 'Nom', 'Prénom', 'Montant', 'Statut', 'Autorisé le', 'Reçu déposé le', 'Contrôlé le'],
                'lignes' => Paiement::visiblesPar($u)
                    ->when(! empty($filtres['statut']), fn ($q) => $q->where('statut', $filtres['statut']))
                    ->with('demande.stagiaire')->get()
                    ->map(fn (Paiement $p) => [
                        $p->demande->numero_dossier, $p->demande->stagiaire->nom, $p->demande->stagiaire->prenom,
                        number_format((float) $p->montant, 2, ',', ' '), $p->statut->libelle(),
                        $p->date_autorisation?->format('d/m/Y'), $p->date_depot?->format('d/m/Y'), $p->date_validation?->format('d/m/Y'),
                    ])->all(),
            ],
            'effectifs_repas' => [
                'sous_titre' => 'Effectifs — '.($filtres['mois'] ?? now()->format('Y-m')),
                'colonnes' => ['Date', 'Internat', 'Repas', 'Réservés', 'Confirmés', 'Servis', 'Conforme'],
                'lignes' => Menu::visiblesPar($u)
                    ->where('date_repas', 'like', ($filtres['mois'] ?? now()->format('Y-m')).'%')
                    ->with('service', 'internat')
                    ->withCount(['reservations as reserves' => fn ($q) => $q->where('statut', '!=', StatutReservation::Annule->value)])
                    ->orderBy('date_repas')->get()
                    ->map(fn (Menu $m) => [
                        $m->date_repas->format('d/m/Y'), $m->internat->nom, $m->type_repas->libelle(), $m->reserves,
                        $m->service?->effectif_confirme, $m->service?->effectif_servi,
                        $m->service?->conforme === null ? '' : ($m->service->conforme ? 'Oui' : 'Non'),
                    ])->all(),
            ],
            'indicateurs' => [
                'sous_titre' => 'Indicateurs au '.now()->translatedFormat('j F Y'),
                'colonnes' => ['Code EFP', 'Établissement', 'Lits', 'Occupés', 'Taux (%)', 'Dossiers déposés', 'Complets', 'Admis', 'Confirmés', 'Liste d\'attente'],
                'lignes' => collect($this->tableaux->lignesParEtablissement(
                    app(Perimetre::class)->internatIds($u) ?? Internat::pluck('id')->all()
                ))->map(fn (array $l) => [
                    $l['code_efp'], $l['nom_efp'], $l['lits'], $l['occupes'], $l['taux'], $l['deposes'],
                    $l['complets'], $l['admis'], $l['confirmes'], $l['en_attente'],
                ])->all(),
            ],
            default => throw new RegleMetierException('Type d\'export inconnu.', 'type_inconnu'),
        };
    }

    /** @return array{sous_titre: string, colonnes: list<string>, lignes: list<list<string|int|null>>} */
    private function listeAdmission(string $type, Utilisateur $u, int $campagneId): array
    {
        $campagne = Campagne::visiblesPar($u)->with('internat')->findOrFail($campagneId);
        $principale = $type === 'liste_admis';

        $dossiers = Demande::where('campagne_id', $campagne->id)
            ->when(
                $campagne->estDeliberee(),
                fn ($q) => $q->whereIn('statut', $principale
                    ? [StatutDemande::Admis->value, StatutDemande::Confirme->value, StatutDemande::SejourTermine->value]
                    : [StatutDemande::EnAttente->value]),
                fn ($q) => $q->where('statut', StatutDemande::Complet->value)
                    ->where('liste', $principale ? ListeAdmission::Principale->value : ListeAdmission::Attente->value),
            )
            ->with('stagiaire')->orderBy('rang')->orderBy('date_soumission')->get();

        return [
            'sous_titre' => "{$campagne->internat->nom} — campagne {$campagne->annee}"
                .($campagne->estDeliberee() ? ' — validée le '.$campagne->date_deliberation->format('d/m/Y') : ' — proposition non validée'),
            'colonnes' => ['Rang', 'N° dossier', 'CEF', 'Nom', 'Prénom', 'Filière', 'Score / 80', 'Statut'],
            'lignes' => $dossiers->map(fn (Demande $d) => [
                $d->rang, $d->numero_dossier, $d->stagiaire->cef, $d->stagiaire->nom, $d->stagiaire->prenom,
                $d->stagiaire->nom_filiere, $d->score, $d->statut->libelle(),
            ])->all(),
        ];
    }

    /**
     * CSV lisible par un tableur : BOM UTF-8, séparateur « ; », et neutralisation
     * des cellules commençant par un caractère de formule.
     *
     * @param  list<string>  $colonnes
     * @param  list<list<mixed>>  $lignes
     */
    private function csv(array $colonnes, array $lignes): string
    {
        $cellule = function (mixed $valeur): string {
            $texte = (string) ($valeur ?? '');

            if ($texte !== '' && in_array($texte[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($texte)) {
                $texte = "'".$texte;
            }

            return '"'.str_replace('"', '""', $texte).'"';
        };

        $sortie = [implode(';', array_map($cellule, $colonnes))];

        foreach ($lignes as $ligne) {
            $sortie[] = implode(';', array_map($cellule, $ligne));
        }

        return "\xEF\xBB\xBF".implode("\r\n", $sortie)."\r\n";
    }
}
