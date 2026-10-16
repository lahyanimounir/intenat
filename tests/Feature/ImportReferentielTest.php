<?php

namespace Tests\Feature;

use App\Models\Utilisateur;
use App\Models\Internat;
use App\Livewire\Personnel\ImportReferentiel as EcranImport;
use App\Domain\Shared\RegleMetierException;
use App\Domain\Accommodation\ImportReferentielCsv;
use App\Enums\StatutChambre;
use App\Enums\StatutImport;
use App\Enums\StatutLit;
use App\Enums\TypeChambre;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\ImportReferentiel;
use App\Models\Lit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Import CSV du référentiel : contrôle de l'en-tête, des lignes, des doublons
 * et de la capacité ; aperçu ; import transactionnel ; cloisonnement par internat.
 */
class ImportReferentielTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private const ENTETE = 'batiment_code;batiment_nom;nombre_etages;chambre_numero;etage;type_chambre;capacite;lit_numero';

    /** Contenu du modèle officiel `referentiel-internat-modele.csv` fourni avec la documentation (données d'exemple, aucune donnée réelle). */
    private const MODELE_OFFICIEL = <<<'CSV'
        code_batiment;nom_batiment;genre;nombre_etages;statut_batiment;equipements_communs;numero_chambre;etage;type_chambre;capacite;equipements_chambre;statut_chambre;numero_lit;statut_lit
        A;Bloc Filles 1;Fille;2;Ouvert;Salle d'étude|Douches collectives|Accès PMR;A-001;0;PMR;2;Salle de bain privée;Disponible;1;Disponible
        A;Bloc Filles 1;Fille;2;Ouvert;Salle d'étude|Douches collectives|Accès PMR;A-001;0;PMR;2;Salle de bain privée;Disponible;2;Disponible
        A;Bloc Filles 1;Fille;2;Ouvert;Salle d'étude|Douches collectives|Accès PMR;A-101;1;Standard;2;;Disponible;1;Disponible
        A;Bloc Filles 1;Fille;2;Ouvert;Salle d'étude|Douches collectives|Accès PMR;A-101;1;Standard;2;;Disponible;2;Hors service
        A;Bloc Filles 1;Fille;2;Ouvert;Salle d'étude|Douches collectives|Accès PMR;A-102;1;Isolement;1;Salle de bain privée;Disponible;1;Disponible
        B;Bloc Garçons 1;Garçon;3;Ouvert;Salle d'étude|Douches collectives;B-101;1;Standard;2;;Disponible;1;Disponible
        B;Bloc Garçons 1;Garçon;3;Ouvert;Salle d'étude|Douches collectives;B-101;1;Standard;2;;Disponible;2;Disponible
        B;Bloc Garçons 1;Garçon;3;Ouvert;Salle d'étude|Douches collectives;B-201;2;Standard;1;;En maintenance;1;Hors service
        CSV;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private static function enteteOfficielle(): string
    {
        return trim(explode("\n", self::MODELE_OFFICIEL)[0]);
    }

    /** @param list<string> $lignes */
    private function csv(array $lignes, string $entete = self::ENTETE, ?string $encodage = null): UploadedFile
    {
        $contenu = implode("\r\n", array_merge([$entete], $lignes))."\r\n";

        if ($encodage !== null) {
            $contenu = mb_convert_encoding($contenu, $encodage, 'UTF-8');
        }

        return UploadedFile::fake()->createWithContent('referentiel.csv', $contenu);
    }

    private function analyser(Utilisateur $gestionnaire, UploadedFile $fichier): ImportReferentiel
    {
        $this->agirEnTantQue($gestionnaire);

        return app(ImportReferentielCsv::class)->analyser($fichier, Internat::findOrFail($gestionnaire->internat_id), $gestionnaire);
    }

    private function confirmer(ImportReferentiel $import, ?Utilisateur $gestionnaire = null): ImportReferentiel
    {
        $gestionnaire ??= Utilisateur::findOrFail($import->importe_par);
        $this->agirEnTantQue($gestionnaire);

        return app(ImportReferentielCsv::class)->confirmer($import->fresh(), $gestionnaire);
    }

    /** Un import ne se confirme que s'il n'a aucune erreur et au moins une ligne à écrire. */
    private static function importable(ImportReferentiel $import): bool
    {
        return $import->lignes_erreur === 0 && ($import->rapport['erreurs_fichier'] ?? []) === [] && $import->lignes_valides > 0;
    }

    private function refuse(callable $action, ?int $statut = null): RegleMetierException
    {
        try {
            $action();
        } catch (RegleMetierException $regle) {
            if ($statut !== null) {
                $this->assertSame($statut, $regle->statutHttp, $regle->getMessage());
            }

            return $regle;
        }

        $this->fail('L\'opération aurait dû être refusée par une règle de gestion.');
    }
    public function test_un_fichier_valide_est_analyse_puis_importe_dans_l_internat_du_gestionnaire(): void
    {
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv([
            'A;Bloc A;2;A-101;1;Standard;2;1',
            'A;Bloc A;2;A-101;1;Standard;2;2',
            'A;Bloc A;2;A-001;0;PMR;1;1',
        ]));

        $this->assertTrue(self::importable($analyse));
        $this->assertSame([3, 0], [$analyse->lignes_valides, $analyse->lignes_erreur]);
        $this->assertSame(['batiments' => 1, 'chambres' => 2, 'lits' => 3], $analyse->rapport['synthese']);
        $this->assertCount(3, $analyse->rapport['apercu']);

        // L'analyse n'écrit rien dans le référentiel.
        $this->assertSame(0, Batiment::count());

        $importe = $this->confirmer($analyse);
        $this->assertSame(StatutImport::Importe, $importe->statut);
        $this->assertSame(['batiments' => 1, 'chambres' => 2, 'lits' => 3], $importe->rapport['resultat']);

        $batiment = Batiment::firstOrFail();
        $this->assertSame($this->filles->id, $batiment->internat_id);
        $this->assertSame([2, 3], [Chambre::count(), Lit::count()]);
        $this->assertSame(TypeChambre::Pmr, Chambre::where('numero', 'A-001')->first()->type_chambre);
        $this->assertDatabaseHas('journal_audit', ['action' => 'referentiel.import', 'utilisateur_id' => $this->gestionnaireFilles->id]);

        // Un import déjà traité ne se rejoue pas.
        $this->refuse(fn () => $this->confirmer($analyse), 409);
        $this->assertSame(3, Lit::count());
    }

    public function test_l_internat_cible_vient_du_compte_jamais_de_la_requete_ni_du_fichier(): void
    {
        // Désigner l'internat voisin depuis l'écran : refusé.
        $this->ecran($this->gestionnaireFilles, EcranImport::class)->set('internat', (string) $this->garcons->id)->assertForbidden();

        // Une colonne « internat_id » dans le fichier est ignorée et signalée.
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv(['A;Bloc A;1;A-101;1;Standard;1;1;'.$this->garcons->id], self::ENTETE.';internat_id'));
        $this->assertSame(['internat_id'], $analyse->rapport['colonnes_ignorees']);

        $this->confirmer($analyse);
        $this->assertSame([$this->filles->id], Batiment::pluck('internat_id')->all());

        // Le gestionnaire de l'autre internat ne peut ni rouvrir, ni confirmer, ni lire cet import.
        $autre = $this->analyser($this->gestionnaireFilles, $this->csv(['B;Bloc B;1;B-101;1;Standard;1;1']));
        $this->ecran($this->gestionnaireGarcons, EcranImport::class)->assertDontSee('referentiel.csv')->call('rouvrir', $autre->id)->assertForbidden();
        $this->connecter($this->gestionnaireGarcons)->get(route('hebergement.import.rapport', $autre))->assertForbidden();
        $this->assertSame(0, ImportReferentiel::visiblesPar($this->gestionnaireGarcons)->count());
        $this->assertSame(StatutImport::Analyse, $autre->fresh()->statut);

        // Sa propre gestionnaire le retrouve et lit son rapport.
        $this->connecter($this->gestionnaireFilles)->get(route('hebergement.import.rapport', $autre))->assertOk();
    }

    public function test_les_erreurs_sont_signalees_ligne_par_ligne_et_bloquent_l_import(): void
    {
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv([
            'A;Bloc A;2;A-101;1;Standard;2;1',
            'A;Bloc A;2;A-101;1;Standard;2;1',       // doublon du lit
            'A;Bloc A;2;A-102;5;Standard;2;1',       // étage au-delà du bâtiment
            'A;Bloc A;2;A-103;1;Suite;2;1',          // type inconnu
            'A;Bloc A;2;A-104;1;Standard;deux;1',    // capacité non numérique
            'A;Bloc A;2;;1;Standard;2;1',            // chambre manquante
            'A;Autre nom;2;A-105;1;Standard;2;1',    // bâtiment décrit différemment
            'A;Bloc A;2;A-106;1;Standard;1;1',
            'A;Bloc A;2;A-106;1;Standard;1;2',       // deux lits pour une capacité de 1
        ]));

        $this->assertFalse(self::importable($analyse));
        $messages = collect($analyse->rapport['anomalies'])->where('gravite', 'erreur')->mapWithKeys(fn ($a) => [$a['ligne'] => $a['message']]);

        $this->assertStringContainsString('Doublon', $messages[3]);
        $this->assertStringContainsString('dépasse le nombre d\'étages', $messages[4]);
        $this->assertStringContainsString('Standard, PMR, Isolement ou Infirmerie', $messages[5]);
        $this->assertStringContainsString('Nombre entier attendu', $messages[6]);
        $this->assertStringContainsString('obligatoire', $messages[7]);
        $this->assertStringContainsString('décrit différemment', $messages[8]);
        $this->assertStringContainsString('2 lits pour une capacité de 1', $messages[9]);

        // Rapport d'erreurs téléchargeable ; confirmation refusée ; rien n'est importé.
        $rapport = $this->connecter($this->gestionnaireFilles)->get(route('hebergement.import.rapport', $analyse))->assertOk()->getContent();
        $this->assertStringContainsString('Doublon', $rapport);
        $this->assertSame('import_en_erreur', $this->refuse(fn () => $this->confirmer($analyse))->codeRegle);
        $this->assertSame(0, Lit::count());
        $this->assertSame(StatutImport::Analyse, ImportReferentiel::first()->statut);
    }

    public function test_l_en_tete_est_controle_avant_toute_ligne(): void
    {
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv(['A;Bloc A;1'], 'batiment_code;batiment_nom;nombre_etages'));

        $this->assertFalse(self::importable($analyse));
        $this->assertSame('Colonnes obligatoires absentes : chambre_numero, etage, type_chambre, capacite, lit_numero.', $analyse->rapport['erreurs_fichier'][0]);

        // Seul un fichier CSV est accepté.
        $this->assertSame('format_refuse', $this->refuse(fn () => $this->analyser($this->gestionnaireFilles, UploadedFile::fake()->create('referentiel.pdf', 4)))->codeRegle);
    }

    public function test_les_encodages_et_separateurs_courants_sont_acceptes(): void
    {
        $entete = str_replace(';', ',', self::ENTETE).',batiment_statut';

        // Fichier Excel « ANSI » (Windows-1252), séparateur virgule, libellés accentués.
        // La ligne compte une colonne de moins que l'en-tête : erreur de structure signalée.
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv(['A,Bâtiment Élise,1,A-101,1,Standard,1,1'], $entete, 'Windows-1252'));
        $this->assertSame(['Windows-1252', ','], [$analyse->rapport['encodage'], $analyse->rapport['separateur']]);
        $this->assertFalse(self::importable($analyse));

        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv(['A,Bâtiment Élise,1,A-101,1,Standard,1,1,Fermé'], $entete, 'Windows-1252'));
        $this->assertTrue(self::importable($analyse));

        $this->confirmer($analyse);
        $this->assertDatabaseHas('batiment', ['nom' => 'Bâtiment Élise', 'statut' => 'ferme']);
    }

    public function test_un_second_import_complete_le_referentiel_sans_dupliquer_l_existant(): void
    {
        $importer = fn (array $lignes) => $this->confirmer($this->analyser($this->gestionnaireFilles, $this->csv($lignes)));

        $importer(['A;Bloc A;1;A-101;1;Standard;2;1']);

        // Même lit (ignoré), nouveau lit dans la même chambre, nouvelle chambre.
        $second = $importer(['A;Bloc A;1;A-101;1;Standard;2;1', 'A;Bloc A;1;A-101;1;Standard;2;2', 'A;Bloc A;1;A-102;1;Standard;1;1']);
        $this->assertSame(['batiments' => 0, 'chambres' => 1, 'lits' => 2], $second->rapport['resultat']);
        $this->assertSame(1, $second->rapport['lignes_existantes']);

        $this->assertSame([1, 2, 3], [Batiment::count(), Chambre::count(), Lit::count()]);

        // Dépasser la capacité déjà enregistrée est refusé.
        $this->refuse(fn () => $importer(['A;Bloc A;1;A-101;1;Standard;2;3']));
        $this->assertSame(3, Lit::count());
    }

    public function test_le_modele_de_fichier_est_lui_meme_importable(): void
    {
        $modele = $this->connecter($this->gestionnaireGarcons)->get(route('hebergement.import.modele'))->assertOk()->getContent();

        // Mêmes en-têtes que le modèle officiel, exemples au genre de l'internat du gestionnaire.
        $this->assertStringContainsString(self::enteteOfficielle(), $modele);
        $this->assertStringContainsString(';Garçon;', $modele);
        $this->assertStringNotContainsString(';Fille;', $modele);

        $analyse = $this->analyser($this->gestionnaireGarcons, UploadedFile::fake()->createWithContent('modele.csv', $modele));
        $this->assertTrue(self::importable($analyse));
        $this->assertSame(['UTF-8 (BOM)', 3, 0], [$analyse->rapport['encodage'], $analyse->lignes_valides, $analyse->rapport['lignes_hors_internat']]);
    }

    public function test_le_modele_officiel_est_accepte_et_chaque_gestionnaire_n_importe_que_son_internat(): void
    {
        $fichier = fn () => UploadedFile::fake()->createWithContent('referentiel-internat-modele.csv', "\xEF\xBB\xBF".self::MODELE_OFFICIEL);

        // Le fichier décrit un bloc filles (5 lits) et un bloc garçons (3 lits) : la gestionnaire des filles n'importe que le sien.
        $analyse = $this->analyser($this->gestionnaireFilles, $fichier());
        $this->assertTrue(self::importable($analyse));
        $this->assertSame([], $analyse->rapport['colonnes_ignorees']);
        $this->assertSame([5, 3, 0], [$analyse->lignes_valides, $analyse->rapport['lignes_hors_internat'], $analyse->lignes_erreur]);
        $this->assertSame(['batiments' => 1, 'chambres' => 3, 'lits' => 5], $analyse->rapport['synthese']);

        // Les lignes de l'autre internat sont dites, pas passées sous silence.
        $this->assertSame(3, collect($analyse->rapport['anomalies'])->where('colonne', 'genre')->where('gravite', 'avertissement')->count());

        $this->confirmer($analyse);

        $this->assertSame([1, 0], [Batiment::where('internat_id', $this->filles->id)->count(), Batiment::where('internat_id', $this->garcons->id)->count()]);
        $this->assertSame(5, Lit::count());

        // Statuts repris du fichier : un lit hors service, une chambre d'isolement disponible.
        $this->assertSame(1, Lit::where('statut', StatutLit::HorsService->value)->count());
        $this->assertSame('isolement', Chambre::where('numero', 'A-102')->firstOrFail()->type_chambre->value);

        // Le gestionnaire des garçons importe le même fichier : il obtient son bloc, et lui seul.
        $analyse = $this->analyser($this->gestionnaireGarcons, $fichier());
        $this->assertSame([3, 5], [$analyse->lignes_valides, $analyse->rapport['lignes_hors_internat']]);
        $this->confirmer($analyse);

        $bloc = Batiment::where('internat_id', $this->garcons->id)->sole();
        $this->assertSame(['B', 'Bloc Garçons 1', 3], [$bloc->code, $bloc->nom, $bloc->nombre_etages]);
        $this->assertSame(StatutChambre::EnMaintenance, Chambre::where('numero', 'B-201')->sole()->statut);
        $this->assertSame(8, Lit::count());
    }

    public function test_un_genre_ou_un_statut_inconnu_et_une_occupation_declaree_sont_refuses(): void
    {
        $analyse = $this->analyser($this->gestionnaireFilles, $this->csv([
            'A;Bloc A;Mixte;2;Ouvert;;A-101;1;Standard;2;;Disponible;1;Disponible',
            'A;Bloc A;Fille;2;Ouvert;;A-102;1;Standard;2;;Occupée;1;Disponible',
            'A;Bloc A;Fille;2;Ouvert;;A-103;1;Standard;2;;Disponible;1;Occupé',
        ], self::enteteOfficielle()));

        $this->assertFalse(self::importable($analyse));
        $this->assertSame(3, $analyse->lignes_erreur);

        // L'occupation ne s'importe pas : elle résulte des affectations.
        $this->assertEqualsCanonicalizing(['genre', 'chambre_statut', 'lit_statut'], array_column($analyse->rapport['anomalies'], 'colonne'));
        $this->assertSame(0, Lit::count());
    }
}
