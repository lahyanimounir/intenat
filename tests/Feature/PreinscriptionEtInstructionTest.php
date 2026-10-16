<?php

namespace Tests\Feature;

use App\Domain\Applications\DossierStagiaire;
use App\Domain\Applications\Instruction;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Livewire\Personnel\DossierFiche;
use App\Livewire\Personnel\Dossiers;
use App\Livewire\Stagiaire\Dossier as EcranDossier;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\PieceJointe;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Préinscription du stagiaire (brouillon, pièces, délai, accusé) et
 * instruction par le gestionnaire (complet, à rectifier, rejet motivé).
 */
class PreinscriptionEtInstructionTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Stagiaire $stagiaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
        $this->stagiaire = $this->creerStagiaire($this->filles);
    }

    private function typePiece(Campagne $campagne, string $libelle): TypePiece
    {
        return $campagne->typesPieces->firstWhere('libelle', $libelle);
    }

    private function pdf(string $nom = 'piece.pdf', int $ko = 40): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, '%PDF-1.4'.str_repeat(' ', $ko * 1024));
    }

    /** Brouillon ouvert par le stagiaire, comme le fait l'écran « Mon dossier ». */
    private function brouillon(?Stagiaire $stagiaire = null): Demande
    {
        $stagiaire ??= $this->stagiaire;
        $this->agirEnTantQue($stagiaire);

        return app(DossierStagiaire::class)->ouvrirBrouillon($stagiaire);
    }

    public function test_un_stagiaire_eligible_constitue_et_depose_son_dossier(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $cin = $this->typePiece($campagne, 'Copie de la CIN');
        $sociale = $this->typePiece($campagne, 'Attestation de situation sociale');
        $dossiers = app(DossierStagiaire::class);

        $demande = $this->brouillon();
        $this->assertSame(StatutDemande::Brouillon, $demande->statut);

        $demande = $dossiers->enregistrer($demande, ['adresse_complete' => '12 rue des Orangers, Settat', 'demuni' => true, 'eloigne_ou_rural' => true]);
        $this->assertTrue($demande->demuni);

        // Déclarer « démuni » rend la pièce correspondante exigible : la soumission est bloquée sans elle.
        $dossiers->deposerPiece($demande, $cin, $this->pdf());
        $refus = $this->assertRegle('pieces_manquantes', fn () => $dossiers->soumettre($demande->fresh()));
        $this->assertSame('Attestation de situation sociale', $refus->contexte['pieces'][0]);

        $dossiers->deposerPiece($demande, $sociale, $this->pdf());
        $demande = $dossiers->soumettre($demande->fresh());
        $this->assertSame(StatutDemande::Recu, $demande->statut);

        // Numéro de dossier unique, date de dépôt, accusé de réception, notification.
        $this->assertSame('INT-2026-T100-F-00001', $demande->numero_dossier);
        $this->assertNotNull($demande->date_soumission);
        $this->connecter($this->stagiaire)->get(route('stagiaire.dossier.accuse'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('notification', ['stagiaire_id' => $this->stagiaire->id, 'titre' => 'Dossier reçu']);
        $this->assertDatabaseHas('notification', ['utilisateur_id' => $this->gestionnaireFilles->id, 'titre' => 'Nouveau dossier à instruire']);
        $this->assertDatabaseMissing('notification', ['utilisateur_id' => $this->gestionnaireGarcons->id]);

        // Une fois déposé, le dossier n'est plus modifiable par le stagiaire.
        $this->agirEnTantQue($this->stagiaire);
        $this->assertRegle('dossier_verrouille', fn () => $dossiers->enregistrer($demande->fresh(), ['adresse_complete' => 'Autre adresse']));
        $this->assertRegle('dossier_verrouille', fn () => $dossiers->deposerPiece($demande->fresh(), $cin, $this->pdf()));
    }

    public function test_les_numeros_de_dossier_sont_uniques_et_sequentiels_par_campagne(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $dossiers = app(DossierStagiaire::class);

        foreach (range(1, 3) as $n) {
            $demande = $this->brouillon($this->creerStagiaire($this->filles));
            $dossiers->enregistrer($demande, ['adresse_complete' => 'Adresse '.$n]);
            $dossiers->deposerPiece($demande, $this->typePiece($campagne, 'Copie de la CIN'), $this->pdf());
            $dossiers->soumettre($demande->fresh());
        }

        $this->assertSame(
            ['INT-2026-T100-F-00001', 'INT-2026-T100-F-00002', 'INT-2026-T100-F-00003'],
            Demande::orderBy('id')->pluck('numero_dossier')->all(),
        );
    }

    public function test_le_depot_hors_delai_ou_hors_campagne_est_refuse_par_le_serveur(): void
    {
        $dossiers = app(DossierStagiaire::class);

        // Aucune campagne ouverte pour son internat : celle des garçons ne compte pas.
        $this->creerCampagne($this->garcons);
        $this->assertRegle('aucune_campagne', fn () => $this->brouillon());
        $this->ecran($this->stagiaire, EcranDossier::class)->call('ouvrir');
        $this->assertSame(0, Demande::count());

        // Campagne ouverte, brouillon créé, puis la date de clôture passe.
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->brouillon();
        $dossiers->enregistrer($demande, ['adresse_complete' => 'Adresse']);
        $dossiers->deposerPiece($demande, $this->typePiece($campagne, 'Copie de la CIN'), $this->pdf());

        $this->travelTo(now()->addDays(40));
        $this->agirEnTantQue($this->stagiaire);
        $this->assertRegle('hors_delai', fn () => $dossiers->soumettre($demande->fresh()));
        $this->ecran($this->stagiaire, EcranDossier::class)->call('soumettre');
        $this->assertSame(StatutDemande::Brouillon, Demande::first()->statut);
        $this->assertNull(Demande::first()->numero_dossier);
    }

    public function test_le_profil_doit_etre_complet_et_le_genre_determine_l_internat(): void
    {
        $this->creerCampagne($this->filles);
        $this->creerCampagne($this->garcons);
        $incomplet = Stagiaire::factory()->create(['etablissement_id' => $this->etablissement->id, 'genre' => null, 'niveau_formation' => null]);

        $this->assertRegle('profil_incomplet', fn () => $this->brouillon($incomplet));
        // L'écran du dossier ne s'ouvre pas non plus : le stagiaire est ramené à son inscription.
        $this->connecter($incomplet)->get('/stagiaire/dossier')->assertRedirect(route('stagiaire.inscription'));

        $incomplet->forceFill(['genre' => Genre::Fille, 'niveau_formation' => NiveauFormation::Technicien])->save();
        $this->assertSame($this->filles->id, $this->brouillon($incomplet->fresh())->campagne->internat_id);
    }

    public function test_les_fichiers_sont_controles_par_type_reel_et_par_taille(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $cin = $this->typePiece($campagne, 'Copie de la CIN');
        $demande = $this->brouillon();
        $deposer = fn (UploadedFile $fichier) => fn () => app(DossierStagiaire::class)->deposerPiece($demande, $cin, $fichier);

        // Exécutable renommé en .pdf : refusé d'après son contenu, pas son extension.
        // (Fichier réel sur disque : les faux fichiers de test déclarent leur type d'après leur nom.)
        $chemin = tempnam(sys_get_temp_dir(), 'internat');
        file_put_contents($chemin, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00 programme");
        $this->assertRegle('format_refuse', $deposer(new UploadedFile($chemin, 'cin.pdf', null, null, true)));
        $this->assertRegle('format_refuse', $deposer(UploadedFile::fake()->create('script.php', 4)));
        $this->assertRegle('taille_depassee', $deposer($this->pdf('gros.pdf', 6000)));

        $this->assertSame(0, PieceJointe::count());
        $this->assertSame([], Storage::disk('prive')->allFiles());
    }

    public function test_le_gestionnaire_declare_un_dossier_complet_et_chaque_decision_est_tracee(): void
    {
        $demande = $this->creerDossierRecu($this->creerCampagne($this->filles), ['demuni' => true]);
        $instruction = app(Instruction::class);
        $this->agirEnTantQue($this->gestionnaireFilles);

        // Impossible de conclure tant qu'une pièce requise n'est pas acceptée.
        $this->assertRegle('pieces_non_acceptees', fn () => $instruction->declarerComplet($demande->fresh(), $this->gestionnaireFilles));

        $fiche = $this->ecran($this->gestionnaireFilles, DossierFiche::class, ['demande' => $demande]);

        foreach ($demande->pieces as $piece) {
            $fiche->call('accepterPiece', $piece->id);
            $this->assertSame(StatutPiece::Acceptee, $piece->fresh()->statut);
        }

        $fiche->call('declarerComplet')->assertHasNoErrors();

        $demande->refresh();
        $this->assertSame([StatutDemande::Complet, $this->gestionnaireFilles->id], [$demande->statut, $demande->instruit_par]);
        // Démuni (20, pièce acceptée) + niveau Technicien Spécialisé (10).
        $this->assertSame(30, $demande->score);

        $trace = HistoriqueDemande::where('demande_id', $demande->id)->latest('id')->first();
        $this->assertSame(['recu', 'complet', $this->gestionnaireFilles->id], [$trace->ancien_statut, $trace->nouveau_statut, $trace->utilisateur_id]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'demande.statut', 'entite_id' => $demande->id, 'utilisateur_id' => $this->gestionnaireFilles->id]);

        // Un dossier vérifié est verrouillé : plus de contrôle de pièce ni de nouvelle décision.
        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertRegle('dossier_verrouille', fn () => $instruction->controlerPiece($demande->pieces[0]->fresh(), StatutPiece::ARemplacer, 'Trop tard', $this->gestionnaireFilles));
        $this->assertRegle('dossier_verrouille', fn () => $instruction->rejeter($demande->fresh(), 'Changement d\'avis tardif', $this->gestionnaireFilles));
        $this->assertSame(StatutDemande::Complet, $demande->fresh()->statut);
    }

    public function test_le_score_et_le_rang_restent_masques_au_gestionnaire(): void
    {
        $demande = $this->rendreComplet($this->creerDossierRecu($this->creerCampagne($this->filles), ['eloigne_ou_rural' => true]));
        $this->assertSame([35, 1], [$demande->score, $demande->rang]);

        // Le gestionnaire instruit sans voir ce que vaut le dossier ; le chef de pôle le voit.
        $this->connecter($this->gestionnaireFilles)->get(route('dossiers.fiche', $demande))->assertOk()
            ->assertDontSee('Détail du score')->assertDontSee('35 / 80');
        $this->connecter($this->gestionnaireFilles)->get('/admissions/classement')->assertForbidden();
        $this->connecter($this->chefDePole)->get(route('dossiers.fiche', $demande))->assertOk()->assertSee('Détail du score')->assertSee('35 / 80');
    }

    public function test_un_renvoi_pour_rectification_rouvre_le_dossier_au_stagiaire(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->creerDossierRecu($campagne, [], $this->stagiaire);
        $piece = $demande->pieces[0];
        $dossiers = app(DossierStagiaire::class);

        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertRegle('commentaire_requis', fn () => app(Instruction::class)->controlerPiece($piece, StatutPiece::ARemplacer, null, $this->gestionnaireFilles));

        $fiche = $this->ecran($this->gestionnaireFilles, DossierFiche::class, ['demande' => $demande]);
        $fiche->call('demanderRemplacement', $piece->id, 'Scan illisible');
        $fiche->call('renvoyer', 'non');
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);
        $fiche->call('renvoyer', 'Merci de déposer un scan lisible de la CIN.');
        $this->assertSame(StatutDemande::RetourRectification, $demande->fresh()->statut);

        // Le stagiaire voit le motif, ne peut pas soumettre sans remplacer la pièce, puis soumet de nouveau.
        $this->ecran($this->stagiaire, EcranDossier::class)->assertSee('Merci de déposer un scan lisible de la CIN.');
        $this->assertTrue($demande->fresh()->estModifiableParLeStagiaire());
        $this->agirEnTantQue($this->stagiaire);
        $this->assertRegle('pieces_manquantes', fn () => $dossiers->soumettre($demande->fresh()));

        $dossiers->deposerPiece($demande->fresh(), $piece->typePiece, $this->pdf('cin-v2.pdf'));
        $this->assertSame(StatutDemande::Recu, $dossiers->soumettre($demande->fresh())->statut);

        // Le numéro et la date du premier dépôt sont conservés ; la pièce repasse à « Déposée ».
        $this->assertSame($demande->numero_dossier, $demande->fresh()->numero_dossier);
        $this->assertEquals($demande->date_soumission, $demande->fresh()->date_soumission);
        $this->assertSame(StatutPiece::Deposee, PieceJointe::where('demande_id', $demande->id)->where('type_piece_id', $piece->type_piece_id)->sole()->statut);
        $this->assertSame(2, HistoriqueDemande::where('demande_id', $demande->id)->count());
    }

    public function test_un_rejet_exige_un_motif_et_un_gestionnaire_d_un_autre_internat_ne_peut_pas_instruire(): void
    {
        $demande = $this->creerDossierRecu($this->creerCampagne($this->filles));

        // Depuis un autre internat, la fiche ne s'ouvre pas : ni décision, ni contrôle de pièce.
        $this->ecran($this->gestionnaireGarcons, DossierFiche::class, ['demande' => $demande])->assertForbidden();
        $this->connecter($this->gestionnaireGarcons)->get(route('dossiers.piece', $demande->pieces[0]))->assertForbidden();
        $this->assertSame(StatutPiece::Deposee, $demande->pieces[0]->fresh()->statut);

        $fiche = $this->ecran($this->gestionnaireFilles, DossierFiche::class, ['demande' => $demande]);
        $fiche->call('rejeter')->assertHasErrors('motif');
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);
        $fiche->call('rejeter', 'Stagiaire non inscrit dans l\'établissement.')->assertHasNoErrors();

        $this->assertSame([StatutDemande::Rejete, 'Stagiaire non inscrit dans l\'établissement.'], [$demande->fresh()->statut, $demande->fresh()->motif_rejet]);
        $this->assertNull($demande->fresh()->score);
    }

    public function test_un_brouillon_reste_prive_au_stagiaire(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $brouillon = $this->brouillon();

        $this->ecran($this->gestionnaireFilles, Dossiers::class)->assertSee('Aucun dossier déposé')->assertDontSee($this->stagiaire->cef);
        $this->connecter($this->gestionnaireFilles)->get(route('dossiers.fiche', $brouillon))->assertNotFound();
        $this->assertSame(StatutCampagne::Ouverte, $campagne->fresh()->statut);
    }
}
