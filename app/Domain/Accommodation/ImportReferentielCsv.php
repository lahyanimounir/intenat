<?php

namespace App\Domain\Accommodation;

use App\Domain\Audit\Journal;
use App\Domain\Shared\Documents;
use App\Domain\Shared\RegleMetierException;
use App\Enums\Genre;
use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutImport;
use App\Enums\StatutLit;
use App\Enums\TypeChambre;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\ImportReferentiel;
use App\Models\Internat;
use App\Models\Lit;
use App\Models\Utilisateur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import CSV du référentiel (bâtiments, chambres, lits) d'un internat.
 *
 * Deux temps : `analyser()` contrôle le fichier et produit un aperçu et un
 * rapport ; `confirmer()` importe, en une seule transaction, un fichier sans
 * erreur. L'internat cible vient toujours du serveur (compte du gestionnaire),
 * jamais d'une colonne du fichier ni d'un paramètre libre de la requête.
 *
 * Format : une ligne par lit, séparateur « ; » ou « , », UTF-8 ou Windows-1252.
 * Les en-têtes du modèle officiel (`referentiel-internat-modele.csv` :
 * `code_batiment`, `numero_chambre`, `numero_lit`…) sont acceptés tels quels.
 *
 * Le modèle officiel porte une colonne `genre` par bâtiment et décrit, dans
 * un même fichier, des bâtiments de filles et de garçons. Chaque internat ayant
 * désormais son gestionnaire, un import ne crée que les lignes du genre de
 * l'internat cible ; celles de l'autre genre sont signalées et laissées au
 * gestionnaire de l'autre internat — jamais importées en silence, jamais non
 * plus reclassées.
 */
class ImportReferentielCsv
{
    public const COLONNES_REQUISES = [
        'batiment_code', 'batiment_nom', 'nombre_etages', 'chambre_numero', 'etage', 'type_chambre', 'capacite', 'lit_numero',
    ];

    public const COLONNES_FACULTATIVES = ['genre', 'batiment_statut', 'equipements_communs', 'chambre_equipements', 'chambre_statut', 'lit_statut'];

    /** En-têtes du modèle officiel → noms internes. */
    private const ALIAS = [
        'code_batiment' => 'batiment_code',
        'nom_batiment' => 'batiment_nom',
        'statut_batiment' => 'batiment_statut',
        'numero_chambre' => 'chambre_numero',
        'equipements_chambre' => 'chambre_equipements',
        'statut_chambre' => 'chambre_statut',
        'numero_lit' => 'lit_numero',
        'statut_lit' => 'lit_statut',
    ];

    /** Ordre et noms des colonnes du modèle officiel. */
    private const ENTETE_MODELE = [
        'code_batiment', 'nom_batiment', 'genre', 'nombre_etages', 'statut_batiment', 'equipements_communs', 'numero_chambre',
        'etage', 'type_chambre', 'capacite', 'equipements_chambre', 'statut_chambre', 'numero_lit', 'statut_lit',
    ];

    private const LIGNES_MAX = 20000;

    private const APERCU = 25;

    public function __construct(
        private readonly Documents $documents,
        private readonly Journal $journal,
    ) {}

    /** Modèle de fichier remis aux gestionnaires : en-têtes du modèle officiel, exemples au genre de l'internat. */
    public function modele(Genre $genre = Genre::Fille): string
    {
        $g = $genre->libelle();
        $communs = 'Salle d\'étude|Douches collectives';

        $lignes = [
            self::ENTETE_MODELE,
            ['A', 'Bloc A', $g, '2', 'Ouvert', $communs, 'A-101', '1', 'Standard', '2', '', 'Disponible', '1', 'Disponible'],
            ['A', 'Bloc A', $g, '2', 'Ouvert', $communs, 'A-101', '1', 'Standard', '2', '', 'Disponible', '2', 'Disponible'],
            ['A', 'Bloc A', $g, '2', 'Ouvert', $communs, 'A-001', '0', 'PMR', '1', 'Salle de bain adaptée', 'Disponible', '1', 'Disponible'],
        ];

        return "\xEF\xBB\xBF".implode("\r\n", array_map(fn (array $l) => implode(';', $l), $lignes))."\r\n";
    }

    public function analyser(UploadedFile $fichier, Internat $internat, Utilisateur $auteur): ImportReferentiel
    {
        $stocke = $this->documents->enregistrer($fichier, "imports/{$internat->id}", ['csv'], 2048);
        $rapport = $this->controler($this->documents->disque()->get($stocke['chemin']), $internat);

        return ImportReferentiel::create([
            'internat_id' => $internat->id,
            'importe_par' => $auteur->id,
            'fichier' => $stocke['chemin'],
            'nom_original' => $stocke['nom_original'],
            'statut' => StatutImport::Analyse,
            'total_lignes' => $rapport['total_lignes'],
            'lignes_valides' => $rapport['lignes_valides'],
            'lignes_erreur' => $rapport['lignes_erreur'],
            'rapport' => $rapport,
        ]);
    }

    public function confirmer(ImportReferentiel $import, Utilisateur $auteur): ImportReferentiel
    {
        return DB::transaction(function () use ($import) {
            $import = ImportReferentiel::lockForUpdate()->findOrFail($import->id);

            if ($import->statut !== StatutImport::Analyse) {
                throw RegleMetierException::conflit('Cet import a déjà été traité.', 'import_traite');
            }

            // Verrou sur l'internat : deux imports simultanés ne se chevauchent pas.
            $internat = Internat::lockForUpdate()->findOrFail($import->internat_id);

            // Le fichier est relu : le référentiel a pu changer depuis l'analyse.
            $rapport = $this->controler($this->documents->disque()->get($import->fichier), $internat, true);

            if ($rapport['lignes_erreur'] > 0 || $rapport['erreurs_fichier'] !== []) {
                $import->forceFill(['rapport' => $this->sansLignes($rapport), 'lignes_erreur' => $rapport['lignes_erreur']])->save();

                throw new RegleMetierException(
                    'Le fichier contient des erreurs : corrigez-le puis relancez l\'analyse.',
                    'import_en_erreur',
                );
            }

            $crees = ['batiments' => 0, 'chambres' => 0, 'lits' => 0];
            $batiments = Batiment::where('internat_id', $internat->id)->get()->keyBy(fn ($b) => mb_strtoupper($b->code));
            $chambres = Chambre::whereIn('batiment_id', $batiments->pluck('id'))->get()
                ->keyBy(fn ($c) => $c->batiment_id.'|'.mb_strtoupper($c->numero));

            foreach ($rapport['lignes'] as $ligne) {
                if ($ligne['etat'] !== 'nouveau') {
                    continue;
                }

                $d = $ligne['donnees'];
                $cleBatiment = mb_strtoupper($d['batiment_code']);

                if (! $batiments->has($cleBatiment)) {
                    $batiments[$cleBatiment] = Batiment::create([
                        'internat_id' => $internat->id,
                        'code' => $d['batiment_code'],
                        'nom' => $d['batiment_nom'],
                        'nombre_etages' => $d['nombre_etages'],
                        'statut' => $d['batiment_statut'],
                        'equipements_communs' => $d['equipements_communs'],
                    ]);
                    $crees['batiments']++;
                }

                $batiment = $batiments[$cleBatiment];
                $cleChambre = $batiment->id.'|'.mb_strtoupper($d['chambre_numero']);

                if (! $chambres->has($cleChambre)) {
                    $chambres[$cleChambre] = Chambre::create([
                        'batiment_id' => $batiment->id,
                        'numero' => $d['chambre_numero'],
                        'etage' => $d['etage'],
                        'type_chambre' => $d['type_chambre'],
                        'capacite' => $d['capacite'],
                        'equipements' => $d['chambre_equipements'],
                        'statut' => $d['chambre_statut'],
                    ]);
                    $crees['chambres']++;
                }

                Lit::create([
                    'chambre_id' => $chambres[$cleChambre]->id,
                    'numero' => $d['lit_numero'],
                    'statut' => $d['lit_statut'],
                ]);
                $crees['lits']++;
            }

            $rapport['resultat'] = $crees;
            $import->forceFill(['statut' => StatutImport::Importe, 'rapport' => $this->sansLignes($rapport)])->save();

            $this->journal->consigner('referentiel.import', $import, details: $crees + ['internat_id' => $internat->id]);

            return $import;
        });
    }

    public function annuler(ImportReferentiel $import): ImportReferentiel
    {
        if ($import->statut !== StatutImport::Analyse) {
            throw RegleMetierException::conflit('Cet import a déjà été traité.', 'import_traite');
        }

        $import->forceFill(['statut' => StatutImport::Annule])->save();

        return $import;
    }

    /** Rapport d'erreurs téléchargeable (CSV, une ligne par anomalie). */
    public function rapportErreurs(ImportReferentiel $import): string
    {
        $rapport = $import->rapport ?? [];
        $sortie = [['ligne', 'gravite', 'colonne', 'message']];

        foreach ($rapport['erreurs_fichier'] ?? [] as $message) {
            $sortie[] = ['', 'erreur', '', $message];
        }
        foreach ($rapport['anomalies'] ?? [] as $a) {
            $sortie[] = [$a['ligne'], $a['gravite'], $a['colonne'] ?? '', $a['message']];
        }

        return "\xEF\xBB\xBF".implode("\r\n", array_map(
            fn (array $l) => implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $l)),
            $sortie,
        ))."\r\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function controler(string $contenu, Internat $internat, bool $avecLignes = false): array
    {
        [$texte, $encodage] = $this->decoder($contenu);
        $lignesBrutes = preg_split('/\r\n|\n|\r/', trim($texte)) ?: [];
        $rapport = [
            'encodage' => $encodage, 'separateur' => null, 'colonnes' => [], 'colonnes_ignorees' => [],
            'erreurs_fichier' => [], 'anomalies' => [], 'apercu' => [], 'total_lignes' => 0,
            'lignes_valides' => 0, 'lignes_erreur' => 0, 'lignes_existantes' => 0, 'lignes_hors_internat' => 0,
            'synthese' => ['batiments' => 0, 'chambres' => 0, 'lits' => 0],
        ];

        if ($lignesBrutes === [] || trim($lignesBrutes[0]) === '') {
            $rapport['erreurs_fichier'][] = 'Le fichier est vide.';

            return $rapport;
        }

        $separateur = $this->separateur($lignesBrutes[0]);
        $entetes = array_map(
            fn ($e) => self::ALIAS[$this->normaliserEntete($e)] ?? $this->normaliserEntete($e),
            str_getcsv($lignesBrutes[0], $separateur, '"', ''),
        );
        $rapport['separateur'] = $separateur === "\t" ? 'tabulation' : $separateur;
        $rapport['colonnes'] = $entetes;

        $manquantes = array_diff(self::COLONNES_REQUISES, $entetes);
        $connues = array_merge(self::COLONNES_REQUISES, self::COLONNES_FACULTATIVES);
        $rapport['colonnes_ignorees'] = array_values(array_diff(array_filter($entetes), $connues));

        if ($manquantes !== []) {
            $rapport['erreurs_fichier'][] = 'Colonnes obligatoires absentes : '.implode(', ', $manquantes).'.';
        }
        if (count($entetes) !== count(array_unique($entetes))) {
            $rapport['erreurs_fichier'][] = 'Une colonne apparaît plusieurs fois dans l\'en-tête.';
        }
        if (count($lignesBrutes) - 1 > self::LIGNES_MAX) {
            $rapport['erreurs_fichier'][] = 'Le fichier dépasse '.self::LIGNES_MAX.' lignes.';
        }
        if ($rapport['erreurs_fichier'] !== []) {
            return $rapport;
        }

        $existant = $this->referentielExistant($internat);
        $lignes = [];
        $vus = ['batiments' => [], 'chambres' => [], 'statuts_chambre' => [], 'lits' => []];

        foreach (array_slice($lignesBrutes, 1) as $index => $brute) {
            $numero = $index + 2;

            if (trim($brute) === '') {
                continue;
            }

            $valeurs = str_getcsv($brute, $separateur, '"', '');
            $rapport['total_lignes']++;

            if (count($valeurs) !== count($entetes)) {
                $lignes[] = $this->ligneEnErreur($rapport, $numero, null, 'Nombre de colonnes différent de l\'en-tête.');

                continue;
            }

            $ligne = array_map(fn ($v) => trim((string) $v), array_combine($entetes, $valeurs));
            [$donnees, $erreurs] = $this->validerLigne($ligne);

            // Bâtiment de l'autre genre : il relève de l'autre internat de l'établissement, et de son gestionnaire.
            if ($erreurs === [] && $donnees['genre'] !== null && $donnees['genre'] !== $internat->genre->value) {
                $rapport['lignes_hors_internat']++;
                $rapport['anomalies'][] = [
                    'ligne' => $numero, 'gravite' => 'avertissement', 'colonne' => 'genre',
                    'message' => 'Bâtiment déclaré « '.Genre::from($donnees['genre'])->libelle().' » : cette ligne relève de l\'autre internat et n\'est pas importée ici.',
                ];
                $lignes[] = ['numero' => $numero, 'etat' => 'hors_internat', 'donnees' => $donnees];

                continue;
            }

            if ($erreurs === []) {
                $erreurs = $this->controlerCoherence($donnees, $vus, $existant, $numero);
            }

            if ($erreurs !== []) {
                foreach ($erreurs as [$colonne, $message]) {
                    $rapport['anomalies'][] = ['ligne' => $numero, 'gravite' => 'erreur', 'colonne' => $colonne, 'message' => $message];
                }
                $rapport['lignes_erreur']++;
                $lignes[] = ['numero' => $numero, 'etat' => 'erreur', 'donnees' => $ligne];

                continue;
            }

            $cleLit = $this->cle($donnees['batiment_code'], $donnees['chambre_numero'], $donnees['lit_numero']);
            $etat = isset($existant['lits'][$cleLit]) ? 'existant' : 'nouveau';

            if ($etat === 'existant') {
                $rapport['lignes_existantes']++;
                $rapport['anomalies'][] = ['ligne' => $numero, 'gravite' => 'information', 'colonne' => 'lit_numero', 'message' => 'Lit déjà présent dans le référentiel : ligne ignorée.'];
            } else {
                $rapport['lignes_valides']++;
            }

            $lignes[] = ['numero' => $numero, 'etat' => $etat, 'donnees' => $donnees];
        }

        $this->controlerCapacites($lignes, $vus, $existant, $rapport);

        $nouveaux = array_filter($lignes, fn ($l) => $l['etat'] === 'nouveau');
        $rapport['synthese'] = [
            'batiments' => count(array_diff_key($vus['batiments'], $existant['batiments'])),
            'chambres' => count(array_diff_key($vus['chambres'], $existant['chambres'])),
            'lits' => count($nouveaux),
        ];
        $rapport['lignes_valides'] = count($nouveaux);
        $rapport['apercu'] = array_slice($lignes, 0, self::APERCU);

        if ($avecLignes) {
            $rapport['lignes'] = $lignes;
        }

        return $rapport;
    }

    /**
     * @param  array<string, string>  $ligne
     * @return array{0: array<string, mixed>, 1: list<array{0: string, 1: string}>}
     */
    private function validerLigne(array $ligne): array
    {
        $erreurs = [];

        foreach (self::COLONNES_REQUISES as $colonne) {
            if (($ligne[$colonne] ?? '') === '') {
                $erreurs[] = [$colonne, 'Valeur obligatoire manquante.'];
            }
        }

        $entier = function (string $colonne, int $min, int $max) use ($ligne, &$erreurs): ?int {
            $valeur = $ligne[$colonne] ?? '';

            if ($valeur === '') {
                return null;
            }
            if (! ctype_digit($valeur) || (int) $valeur < $min || (int) $valeur > $max) {
                $erreurs[] = [$colonne, "Nombre entier attendu, entre {$min} et {$max}."];

                return null;
            }

            return (int) $valeur;
        };

        $etages = $entier('nombre_etages', 0, 30);
        $etage = $entier('etage', 0, 30);
        $capacite = $entier('capacite', 1, 20);

        if ($etages !== null && $etage !== null && $etage > $etages) {
            $erreurs[] = ['etage', "L'étage {$etage} dépasse le nombre d'étages du bâtiment ({$etages})."];
        }

        $type = $this->enum(TypeChambre::class, $ligne['type_chambre'] ?? '');

        if (($ligne['type_chambre'] ?? '') !== '' && $type === null) {
            $erreurs[] = ['type_chambre', 'Valeur attendue : Standard, PMR, Isolement ou Infirmerie.'];
        }

        $statut = StatutBatiment::Ouvert;

        if (($ligne['batiment_statut'] ?? '') !== '') {
            $statut = $this->enum(StatutBatiment::class, $ligne['batiment_statut']);

            if ($statut === null) {
                $erreurs[] = ['batiment_statut', 'Valeur attendue : Ouvert, En travaux ou Fermé.'];
            }
        }

        $genre = null;

        if (($ligne['genre'] ?? '') !== '') {
            $genre = $this->enum(Genre::class, $ligne['genre']);

            if ($genre === null) {
                $erreurs[] = ['genre', 'Valeur attendue : Fille ou Garçon.'];
            }
        }

        // L'occupation ne s'importe pas : elle résulte des affectations.
        $statutChambre = StatutChambre::Disponible;

        if (($ligne['chambre_statut'] ?? '') !== '') {
            $statutChambre = $this->enum(StatutChambre::class, $ligne['chambre_statut']);

            if ($statutChambre === null || $statutChambre === StatutChambre::Occupee) {
                $erreurs[] = ['chambre_statut', 'Valeur attendue : Disponible ou En maintenance.'];
            }
        }

        $statutLit = StatutLit::Disponible;

        if (($ligne['lit_statut'] ?? '') !== '') {
            $statutLit = $this->enum(StatutLit::class, $ligne['lit_statut']);

            if ($statutLit === null || $statutLit === StatutLit::Occupe) {
                $erreurs[] = ['lit_statut', 'Valeur attendue : Disponible ou Hors service.'];
            }
        }

        foreach (['batiment_code' => 20, 'batiment_nom' => 100, 'chambre_numero' => 30, 'lit_numero' => 10] as $colonne => $max) {
            if (mb_strlen($ligne[$colonne] ?? '') > $max) {
                $erreurs[] = [$colonne, "Longueur maximale : {$max} caractères."];
            }
        }

        return [[
            'batiment_code' => $ligne['batiment_code'] ?? '',
            'batiment_nom' => $ligne['batiment_nom'] ?? '',
            'genre' => $genre?->value,
            'nombre_etages' => $etages,
            'batiment_statut' => $statut?->value,
            'equipements_communs' => ($ligne['equipements_communs'] ?? '') ?: null,
            'chambre_numero' => $ligne['chambre_numero'] ?? '',
            'etage' => $etage,
            'type_chambre' => $type?->value,
            'capacite' => $capacite,
            'chambre_equipements' => ($ligne['chambre_equipements'] ?? '') ?: null,
            'chambre_statut' => $statutChambre?->value,
            'lit_numero' => $ligne['lit_numero'] ?? '',
            'lit_statut' => $statutLit?->value,
        ], $erreurs];
    }

    /**
     * Cohérence d'une ligne avec les lignes précédentes et le référentiel existant.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, array<string, mixed>>  $vus
     * @param  array<string, array<string, mixed>>  $existant
     * @return list<array{0: string, 1: string}>
     */
    private function controlerCoherence(array $d, array &$vus, array $existant, int $numero): array
    {
        $erreurs = [];
        $cleB = $this->cle($d['batiment_code']);
        $cleC = $this->cle($d['batiment_code'], $d['chambre_numero']);
        $cleL = $this->cle($d['batiment_code'], $d['chambre_numero'], $d['lit_numero']);

        $batiment = ['nom' => $d['batiment_nom'], 'etages' => $d['nombre_etages']];
        $chambre = ['etage' => $d['etage'], 'type' => $d['type_chambre'], 'capacite' => $d['capacite']];

        if (isset($vus['batiments'][$cleB]) && $vus['batiments'][$cleB] != $batiment) {
            $erreurs[] = ['batiment_code', 'Ce bâtiment est décrit différemment sur une autre ligne (nom ou nombre d\'étages).'];
        }
        if (isset($vus['chambres'][$cleC]) && $vus['chambres'][$cleC] != $chambre) {
            $erreurs[] = ['chambre_numero', 'Cette chambre est décrite différemment sur une autre ligne (étage, type ou capacité).'];
        }
        if (isset($existant['chambres'][$cleC]) && $existant['chambres'][$cleC] != $chambre) {
            $erreurs[] = ['chambre_numero', 'Cette chambre existe déjà avec un étage, un type ou une capacité différents.'];
        }
        if (isset($vus['statuts_chambre'][$cleC]) && $vus['statuts_chambre'][$cleC] !== $d['chambre_statut']) {
            $erreurs[] = ['chambre_statut', 'Cette chambre porte un autre statut sur une autre ligne.'];
        }
        if (isset($vus['lits'][$cleL])) {
            $erreurs[] = ['lit_numero', "Doublon : ce lit figure déjà à la ligne {$vus['lits'][$cleL]}."];
        }

        if ($erreurs === []) {
            $vus['batiments'][$cleB] = $batiment;
            $vus['chambres'][$cleC] = $chambre;
            $vus['statuts_chambre'][$cleC] = $d['chambre_statut'];
            $vus['lits'][$cleL] = $numero;
        }

        return $erreurs;
    }

    /**
     * La capacité d'une chambre est contrôlée à l'import : ni plus de lits que
     * prévu (erreur), ni moins sans le signaler (avertissement).
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, array<string, mixed>>  $vus
     * @param  array<string, array<string, mixed>>  $existant
     * @param  array<string, mixed>  $rapport
     */
    private function controlerCapacites(array &$lignes, array $vus, array $existant, array &$rapport): void
    {
        $parChambre = [];

        foreach ($lignes as $i => $ligne) {
            if ($ligne['etat'] === 'nouveau') {
                $parChambre[$this->cle($ligne['donnees']['batiment_code'], $ligne['donnees']['chambre_numero'])][] = $i;
            }
        }

        foreach ($parChambre as $cle => $indices) {
            $capacite = $vus['chambres'][$cle]['capacite'];
            $total = count($indices) + ($existant['lits_par_chambre'][$cle] ?? 0);
            $premiere = $lignes[$indices[0]]['numero'];

            if ($total > $capacite) {
                foreach ($indices as $i) {
                    $lignes[$i]['etat'] = 'erreur';
                    $rapport['lignes_erreur']++;
                    $rapport['anomalies'][] = [
                        'ligne' => $lignes[$i]['numero'], 'gravite' => 'erreur', 'colonne' => 'capacite',
                        'message' => "La chambre compterait {$total} lits pour une capacité de {$capacite}.",
                    ];
                }
            } elseif ($total < $capacite) {
                $rapport['anomalies'][] = [
                    'ligne' => $premiere, 'gravite' => 'avertissement', 'colonne' => 'capacite',
                    'message' => "La chambre ne compte que {$total} lit(s) pour une capacité de {$capacite}.",
                ];
            }
        }
    }

    /** @return array{batiments: array<string, mixed>, chambres: array<string, mixed>, lits: array<string, bool>, lits_par_chambre: array<string, int>} */
    private function referentielExistant(Internat $internat): array
    {
        $existant = ['batiments' => [], 'chambres' => [], 'lits' => [], 'lits_par_chambre' => []];

        $batiments = Batiment::where('internat_id', $internat->id)->with('chambres.lits')->get();

        foreach ($batiments as $batiment) {
            $existant['batiments'][$this->cle($batiment->code)] = ['nom' => $batiment->nom, 'etages' => $batiment->nombre_etages];

            foreach ($batiment->chambres as $chambre) {
                $cleC = $this->cle($batiment->code, $chambre->numero);
                $existant['chambres'][$cleC] = [
                    'etage' => $chambre->etage, 'type' => $chambre->type_chambre->value, 'capacite' => $chambre->capacite,
                ];
                $existant['lits_par_chambre'][$cleC] = $chambre->lits->count();

                foreach ($chambre->lits as $lit) {
                    $existant['lits'][$this->cle($batiment->code, $chambre->numero, $lit->numero)] = true;
                }
            }
        }

        return $existant;
    }

    /**
     * @param  array<string, mixed>  $rapport
     * @return array<string, mixed>
     */
    private function ligneEnErreur(array &$rapport, int $numero, ?string $colonne, string $message): array
    {
        $rapport['anomalies'][] = ['ligne' => $numero, 'gravite' => 'erreur', 'colonne' => $colonne, 'message' => $message];
        $rapport['lignes_erreur']++;

        return ['numero' => $numero, 'etat' => 'erreur', 'donnees' => []];
    }

    /**
     * @param  array<string, mixed>  $rapport
     * @return array<string, mixed>
     */
    private function sansLignes(array $rapport): array
    {
        unset($rapport['lignes']);

        return $rapport;
    }

    /** @return array{0: string, 1: string} */
    private function decoder(string $contenu): array
    {
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            return [substr($contenu, 3), 'UTF-8 (BOM)'];
        }

        if (mb_check_encoding($contenu, 'UTF-8')) {
            return [$contenu, 'UTF-8'];
        }

        return [mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    private function separateur(string $entete): string
    {
        $compte = [';' => substr_count($entete, ';'), ',' => substr_count($entete, ','), "\t" => substr_count($entete, "\t")];
        arsort($compte);

        return (string) array_key_first($compte);
    }

    private function normaliserEntete(string $entete): string
    {
        $texte = strtr(mb_strtolower(trim($entete)), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $texte), '_');
    }

    /**
     * Accepte le code (`en_travaux`) ou le libellé (« En travaux »), sans tenir compte de la casse ni des accents.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enum(string $enum, string $valeur): ?\BackedEnum
    {
        $attendu = $this->normaliserEntete($valeur);

        foreach ($enum::cases() as $cas) {
            if ($cas->value === $attendu || $this->normaliserEntete($cas->libelle()) === $attendu) {
                return $cas;
            }
        }

        return null;
    }

    private function cle(string ...$parties): string
    {
        return mb_strtoupper(implode('|', array_map('trim', $parties)));
    }
}
