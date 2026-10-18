<?php

namespace Tests\Feature;

use App\Enums\AnneeFormation;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutInscription;
use App\Livewire\Auth\Connexion;
use App\Livewire\Auth\Inscription;
use App\Livewire\Stagiaire\ConfirmationAdresse;
use App\Domain\Applications\Instruction;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Livewire\Stagiaire\MonInscription;
use App\Models\Demande;
use App\Models\Campagne;
use App\Models\JournalAudit;
use App\Models\Stagiaire;
use App\Notifications\VerificationAdresse;
use App\Support\Affichage\Menu;
use App\Support\AnneeScolaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Inscription manuelle d'un stagiaire, sans MyWay : création du compte, puis
 * déclaration de l'identité et de la formation. Rien de ce qui est déclaré ici
 * ne vaut inscription vérifiée (voir VerificationInscriptionTest).
 */
class InscriptionStagiaireTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private const MOT_DE_PASSE = 'Compte-Stagiaire-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private function identite(array $remplace = []): array
    {
        return $remplace + [
            'cef' => '2026000000123', 'nom' => 'El Amrani', 'prenom' => 'Salma', 'cni' => 'AB123456', 'genre' => Genre::Fille->value,
            'tel' => '0612345678', 'ville' => 'Casablanca', 'adresse' => '12 rue des Orangers, Hay Hassani',
        ];
    }

    private function formation(array $remplace = []): array
    {
        return $remplace + [
            'etablissement_id' => $this->etablissement->id, 'nom_filiere' => 'Développement digital',
            'niveau_formation' => NiveauFormation::TechnicienSpecialise->value, 'annee_formation' => AnneeFormation::Premiere->value,
            'annee_scolaire' => AnneeScolaire::courante(),
        ];
    }

    /** Petit PDF valide pour les dépôts de pièces. */
    private function fichier(string $nom = 'piece.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    /** Un candidat soumet une candidature complète (inscription + dossier) pour la campagne donnée. */
    private function soumettreCandidature(Campagne $campagne): Demande
    {
        Storage::fake('prive');
        $cin = $campagne->typesPieces->firstWhere('obligatoire', true);
        $stagiaire = Stagiaire::factory()->nouveau()->create($this->identite());

        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);
        $this->declarerFormation($ecran, $campagne)->call('enregistrerFormation');
        $ecran->set('situation.adresse_complete', '12 rue des Orangers, Hay Hassani')->call('enregistrerSituation');
        $ecran->set("fichiers.{$cin->id}", $this->fichier('cin.pdf'));
        $ecran->set('justificatif', $this->fichier('scolarite.pdf'))->call('soumettre')->assertHasNoErrors();

        return $stagiaire->demandeCourante();
    }

    /**
     * Déroule la cascade Région → Province → Complexe → Établissement puis choisit
     * la campagne, dans l'ordre où les sélecteurs dépendent les uns des autres.
     */
    private function declarerFormation(\Livewire\Features\SupportTesting\Testable $ecran, Campagne $campagne, array $formation = []): \Livewire\Features\SupportTesting\Testable
    {
        $e = $this->etablissement->loadMissing('complexe.province');

        return $ecran
            ->set('regionId', (string) $e->complexe->province->region_id)
            ->set('provinceId', (string) $e->complexe->province_id)
            ->set('complexeId', (string) $e->complexe_id)
            ->set('formation', $this->formation($formation))
            ->set('campagneId', (string) $campagne->id);
    }

    /**
     * Déroule tout l'assistant anonyme (Compte → Identité → Formation → Situation → Pièces)
     * jusqu'au point de soumission, sans appeler `soumettre`. Aucun compte n'est créé.
     */
    private function remplirWizard(string $email, Campagne $campagne): \Livewire\Features\SupportTesting\Testable
    {
        $cin = $campagne->typesPieces->firstWhere('obligatoire', true);
        $e = $this->etablissement->loadMissing('complexe.province');

        return Livewire::test(Inscription::class)
            ->set('compte.email', $email)
            ->set('compte.mot_de_passe', self::MOT_DE_PASSE)
            ->set('compte.mot_de_passe_confirmation', self::MOT_DE_PASSE)
            ->call('continuerCompte')->assertHasNoErrors()
            ->set('identite', $this->identite())
            ->call('continuerIdentite')->assertHasNoErrors()
            ->set('regionId', (string) $e->complexe->province->region_id)
            ->set('provinceId', (string) $e->complexe->province_id)
            ->set('complexeId', (string) $e->complexe_id)
            ->set('formation', $this->formation())
            ->set('campagneId', (string) $campagne->id)
            ->call('continuerFormation')->assertHasNoErrors()
            ->set('situation.adresse_complete', '12 rue des Orangers, Hay Hassani')
            ->call('continuerSituation')->assertHasNoErrors()
            ->set("fichiers.{$cin->id}", $this->fichier('cin.pdf'))
            ->set('justificatif', $this->fichier('scolarite.pdf'));
    }

    /* ------------------------------------- Inscription anonyme (compte différé) */

    public function test_la_candidature_se_remplit_sans_compte_puis_le_compte_est_cree_a_la_soumission(): void
    {
        Storage::fake('prive');
        $campagne = $this->creerCampagne($this->filles);

        $ecran = $this->remplirWizard('Salma.ElAmrani@Exemple.ma', $campagne);

        // Aucune écriture tant que la candidature n'est pas soumise : pas de compte créé.
        $this->assertSame(0, Stagiaire::count());
        $this->assertFalse(Auth::guard('stagiaire')->check());

        $ecran->call('soumettre')->assertHasNoErrors()->assertRedirect(route('stagiaire.inscription'));

        $stagiaire = Stagiaire::where('email', 'salma.elamrani@exemple.ma')->sole();
        $this->assertSame(EtatCompte::Actif, $stagiaire->etat_compte);
        $this->assertSame(StatutInscription::Soumise, $stagiaire->statut_inscription);
        $this->assertTrue($stagiaire->adresseVerifiee());           // aucune confirmation d'e-mail exigée
        $this->assertSame('2026000000123', $stagiaire->cef);
        $this->assertSame(StatutDemande::Recu, $stagiaire->demandeCourante()->statut);
        $this->assertTrue(Auth::guard('stagiaire')->check());
        $this->assertTrue(JournalAudit::where('action', 'stagiaire.inscription')->where('stagiaire_id', $stagiaire->id)->exists());
    }

    public function test_le_mot_de_passe_doit_respecter_la_politique_et_etre_confirme(): void
    {
        $composant = Livewire::test(Inscription::class)->set('compte.email', 'nouveau@exemple.ma');

        $composant->set('compte.mot_de_passe', 'court')->set('compte.mot_de_passe_confirmation', 'court')->call('continuerCompte')->assertHasErrors('compte.mot_de_passe');
        $composant->set('compte.mot_de_passe', self::MOT_DE_PASSE)->set('compte.mot_de_passe_confirmation', 'Autre-Chose-2026')->call('continuerCompte')->assertHasErrors('compte.mot_de_passe');
        $composant->set('compte.mot_de_passe', 'Nouveau-2026-secret')->set('compte.mot_de_passe_confirmation', 'Nouveau-2026-secret')->call('continuerCompte')
            ->assertHasErrors(['compte.mot_de_passe' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.']);

        $this->assertSame(0, Stagiaire::count());
    }

    public function test_une_adresse_deja_prise_affiche_un_message_clair(): void
    {
        Stagiaire::factory()->create(['email' => 'deja@exemple.ma']);

        Livewire::test(Inscription::class)
            ->set('compte.email', 'DEJA@exemple.ma')
            ->set('compte.mot_de_passe', self::MOT_DE_PASSE)
            ->set('compte.mot_de_passe_confirmation', self::MOT_DE_PASSE)
            ->call('continuerCompte')
            ->assertHasErrors(['compte.email' => 'Cette adresse e-mail est déjà utilisée.'])
            ->assertNoRedirect();

        $this->assertSame(1, Stagiaire::count());
        $this->assertFalse(Auth::guard('stagiaire')->check());
    }

    public function test_l_inscription_est_limitee_en_debit(): void
    {
        // Cinq essais par minute sur une même adresse et depuis un même poste ; le sixième est refusé.
        foreach (range(1, 5) as $essai) {
            Livewire::test(Inscription::class)->set('compte.email', 'cible@exemple.ma')
                ->set('compte.mot_de_passe', self::MOT_DE_PASSE)->set('compte.mot_de_passe_confirmation', self::MOT_DE_PASSE)->call('continuerCompte');
        }

        Livewire::test(Inscription::class)->set('compte.email', 'cible@exemple.ma')
            ->set('compte.mot_de_passe', self::MOT_DE_PASSE)->set('compte.mot_de_passe_confirmation', self::MOT_DE_PASSE)->call('continuerCompte')
            ->assertSee('Trop de tentatives');
    }

    /* ------------------------------------------- Confirmation de l'adresse */

    public function test_sans_serveur_d_envoi_l_adresse_n_est_pas_exigee(): void
    {
        config(['mail.default' => 'log', 'internat.inscription.verifier_adresse' => null]);
        $stagiaire = Stagiaire::factory()->nouveau()->adresseNonVerifiee()->create();

        $this->assertFalse($stagiaire->doitVerifierSonAdresse());
        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.inscription'))->assertOk();
    }

    public function test_le_lien_de_confirmation_peut_etre_renvoye(): void
    {
        config(['internat.inscription.verifier_adresse' => true]);
        Notification::fake();
        $stagiaire = Stagiaire::factory()->nouveau()->adresseNonVerifiee()->create();

        Livewire::actingAs($stagiaire, 'stagiaire')->test(ConfirmationAdresse::class)->call('renvoyer')->assertSet('renvoye', true);

        Notification::assertSentTo($stagiaire, VerificationAdresse::class);
    }

    /* ------------------------------------------------- Identité et formation */

    public function test_le_stagiaire_declare_son_identite_puis_sa_formation(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create();

        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class)->assertSet('etape', 'identite');

        $ecran->set('identite', $this->identite(['cef' => '2026 0000 00123', 'tel' => '06 12 34 56 78', 'cni' => 'ab123456']))
            ->call('enregistrerIdentite')
            ->assertHasNoErrors()
            ->assertSet('etape', 'formation');

        $stagiaire->refresh();
        $this->assertSame('2026000000123', $stagiaire->cef);
        $this->assertSame('0612345678', $stagiaire->tel);
        $this->assertSame('AB123456', $stagiaire->cni);
        $this->assertSame(Genre::Fille, $stagiaire->genre);
        // Une identité seule ne suffit pas : la formation reste à déclarer.
        $this->assertSame(StatutInscription::NonDeclaree, $stagiaire->statut_inscription);

        // Une campagne doit être ouverte pour l'internat (filles) de l'établissement visé.
        // La formation enregistrée ouvre le dossier de candidature et mène à l'étape « Situation ».
        $campagne = $this->creerCampagne($this->filles);
        $this->declarerFormation($ecran, $campagne)->call('enregistrerFormation')->assertHasNoErrors()->assertSet('etape', 'situation');

        $stagiaire->refresh();
        $this->assertSame($this->etablissement->id, $stagiaire->etablissement_id);
        $this->assertSame(StatutInscription::Declaree, $stagiaire->statut_inscription);
        $this->assertNotNull($stagiaire->profil_complete_le);
        $this->assertTrue($stagiaire->profilComplet());
        // Déclarer n'est pas être vérifié.
        $this->assertFalse($stagiaire->inscriptionVerifiee());
        $this->assertSame('declare', $stagiaire->origine_champs['cef']);

        // Le journal dit quels champs ont changé, jamais leur valeur.
        $trace = JournalAudit::where('action', 'stagiaire.identite_declaree')->sole();
        $this->assertContains('cef', $trace->details['champs']);
        $this->assertStringNotContainsString('2026000000123', json_encode($trace->details));
    }

    public function test_les_informations_personnelles_sont_validees(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create();
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);

        $ecran->set('identite', $this->identite(['cef' => '12345', 'nom' => 'Salma123', 'cni' => '123', 'tel' => '12345', 'genre' => 'autre', 'adresse' => 'x']))
            ->call('enregistrerIdentite')
            ->assertHasErrors(['identite.cef', 'identite.nom', 'identite.cni', 'identite.tel', 'identite.genre', 'identite.adresse']);

        $ecran->set('identite', $this->identite(['cef' => '', 'prenom' => '', 'ville' => '']))
            ->call('enregistrerIdentite')
            ->assertHasErrors(['identite.cef', 'identite.prenom', 'identite.ville']);

        // La CNI est facultative.
        $ecran->set('identite', $this->identite(['cni' => '']))->call('enregistrerIdentite')->assertHasNoErrors();
        $this->assertNull($stagiaire->fresh()->cni);
    }

    public function test_un_cef_deja_rattache_a_un_autre_compte_est_refuse(): void
    {
        Stagiaire::factory()->create(['cef' => '2026000000123']);
        $stagiaire = Stagiaire::factory()->nouveau()->create();

        Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class)
            ->set('identite', $this->identite())
            ->call('enregistrerIdentite')
            ->assertHasErrors('identite.cef');

        $this->assertNull($stagiaire->fresh()->cef);
        $this->assertSame(1, Stagiaire::where('cef', '2026000000123')->count());
    }

    public function test_l_etablissement_vient_du_referentiel_et_jamais_d_une_saisie_libre(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create($this->identite());
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);

        $ecran->set('formation', $this->formation(['etablissement_id' => 999999]))->call('enregistrerFormation')->assertHasErrors('formation.etablissement_id');
        $ecran->set('formation', $this->formation(['etablissement_id' => 'ISTA Imaginaire']))->call('enregistrerFormation')->assertHasErrors('formation.etablissement_id');
        $ecran->set('formation', $this->formation(['annee_scolaire' => '1999-2000', 'niveau_formation' => 'doctorat']))->call('enregistrerFormation')
            ->assertHasErrors(['formation.annee_scolaire', 'formation.niveau_formation']);

        $this->assertNull($stagiaire->fresh()->etablissement_id);
    }

    public function test_la_formation_exige_une_campagne_ouverte_et_la_hierarchie_complete(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create($this->identite());
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);
        $e = $this->etablissement->loadMissing('complexe.province');

        // Hiérarchie complète mais aucune campagne ouverte : l'inscription est bloquée.
        $ecran->set('regionId', (string) $e->complexe->province->region_id)
            ->set('provinceId', (string) $e->complexe->province_id)
            ->set('complexeId', (string) $e->complexe_id)
            ->set('formation', $this->formation())
            ->call('enregistrerFormation')
            ->assertHasErrors('campagneId');
        $this->assertNull($stagiaire->fresh()->etablissement_id);

        // Une campagne ouverte d'un AUTRE internat (garçons) ne vaut pas pour une candidate.
        $autreCampagne = $this->creerCampagne($this->garcons);
        $this->declarerFormation($ecran, $autreCampagne)
            ->call('enregistrerFormation')
            ->assertHasErrors('campagneId');

        // La bonne campagne ouverte (internat filles) débloque la déclaration.
        $campagne = $this->creerCampagne($this->filles);
        $this->declarerFormation($ecran, $campagne)
            ->call('enregistrerFormation')
            ->assertHasNoErrors()
            ->assertSet('etape', 'situation');
        $this->assertSame($this->etablissement->id, $stagiaire->fresh()->etablissement_id);
    }

    public function test_la_candidature_complete_se_soumet_en_une_fois_au_gestionnaire(): void
    {
        Storage::fake('prive');
        $campagne = $this->creerCampagne($this->filles);
        $cin = $campagne->typesPieces->firstWhere('obligatoire', true);

        $stagiaire = Stagiaire::factory()->nouveau()->create($this->identite());
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);

        // Formation + campagne → ouvre le dossier de candidature, étape « Situation ».
        $this->declarerFormation($ecran, $campagne)->call('enregistrerFormation')->assertHasNoErrors()->assertSet('etape', 'situation');

        // Situation enregistrée → étape « Pièces ».
        $ecran->set('situation.adresse_complete', '12 rue des Orangers, Hay Hassani')
            ->set('situation.eloigne_ou_rural', true)
            ->call('enregistrerSituation')->assertHasNoErrors()->assertSet('etape', 'pieces');

        $demande = $stagiaire->demandeCourante();
        $this->assertNotNull($demande);
        $this->assertTrue($demande->eloigne_ou_rural);

        // Sans la pièce obligatoire, la soumission est refusée et renvoie aux pièces.
        $ecran->set('justificatif', $this->fichier('scolarite.pdf'))->call('soumettre')->assertSet('etape', 'pieces');
        $this->assertSame(StatutDemande::Brouillon, $stagiaire->demandeCourante()->statut);
        $this->assertSame(StatutInscription::Declaree, $stagiaire->fresh()->statut_inscription);

        // Dépôt de la pièce obligatoire, puis soumission unique (inscription + dossier).
        $ecran->set("fichiers.{$cin->id}", $this->fichier('cin.pdf'));
        $ecran->set('justificatif', $this->fichier('scolarite.pdf'))->call('soumettre')->assertHasNoErrors();

        $stagiaire->refresh();
        $demande = $stagiaire->demandeCourante();
        // La candidature part en une fois : inscription soumise + dossier reçu, en attente du gestionnaire.
        $this->assertSame(StatutInscription::Soumise, $stagiaire->statut_inscription);
        $this->assertSame(StatutDemande::Recu, $demande->statut);
        $this->assertNotNull($demande->numero_dossier);
    }

    public function test_la_validation_du_gestionnaire_vaut_inscription_verifiee_et_ouvre_le_sejour(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->soumettreCandidature($campagne);
        $stagiaire = $demande->stagiaire;

        // En attente de la revue unique : inscription soumise, séjour fermé.
        $this->assertSame(StatutInscription::Soumise, $stagiaire->fresh()->statut_inscription);

        // Le gestionnaire contrôle la pièce puis déclare la candidature complète.
        $instruction = app(Instruction::class);
        $instruction->controlerPiece($demande->pieces()->first(), StatutPiece::Acceptee, null, $this->gestionnaireFilles);
        $instruction->declarerComplet($demande->fresh(), $this->gestionnaireFilles);

        // Revue unique : l'inscription est vérifiée, le dossier complet, le séjour ouvert.
        $stagiaire->refresh();
        $this->assertTrue($stagiaire->inscriptionVerifiee());
        $this->assertSame(StatutDemande::Complet, $demande->fresh()->statut);
        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.accueil'))->assertOk();
    }

    public function test_un_retour_pour_rectification_referme_le_sejour_et_renvoie_le_candidat(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->soumettreCandidature($campagne);
        $stagiaire = $demande->stagiaire;

        app(Instruction::class)->renvoyerPourRectification($demande->fresh(), 'Pièce illisible : merci de la redéposer.', $this->gestionnaireFilles);

        // La candidature revient au candidat : inscription « à corriger », séjour fermé.
        $stagiaire->refresh();
        $this->assertSame(StatutInscription::ACorriger, $stagiaire->statut_inscription);
        $this->assertSame(StatutDemande::RetourRectification, $demande->fresh()->statut);
        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.accueil'))->assertRedirect(route('stagiaire.inscription'));
    }

    public function test_une_candidature_retiree_peut_etre_modifiee_et_resoumise_sans_doublon(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->soumettreCandidature($campagne);
        $stagiaire = $demande->stagiaire;
        $numero = $demande->numero_dossier;

        $this->assertSame(StatutInscription::Soumise, $stagiaire->fresh()->statut_inscription);
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);

        $ecran = Livewire::actingAs($stagiaire->fresh(), 'stagiaire')->test(MonInscription::class);

        // Retrait : l'inscription et le dossier redeviennent modifiables, sur la même candidature.
        $ecran->call('retirer')->assertHasNoErrors();
        $stagiaire->refresh();
        $this->assertSame(StatutInscription::Declaree, $stagiaire->statut_inscription);
        $this->assertSame(StatutDemande::Brouillon, $demande->fresh()->statut);

        // Remplacement d'une pièce, puis nouvelle soumission sans re-joindre le justificatif de scolarité.
        $cin = $campagne->typesPieces->firstWhere('obligatoire', true);
        $ecran->set("fichiers.{$cin->id}", $this->fichier('cin-v2.pdf'))->call('soumettre')->assertHasNoErrors();

        $stagiaire->refresh();
        $this->assertSame(StatutInscription::Soumise, $stagiaire->statut_inscription);
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);
        // Même candidature, même numéro : aucun doublon.
        $this->assertSame($numero, $demande->fresh()->numero_dossier);
        $this->assertSame(1, Demande::where('stagiaire_id', $stagiaire->id)->count());
        // Le justificatif de scolarité a été repris automatiquement.
        $this->assertNotNull($stagiaire->verificationsInscription()->enAttente()->latest('id')->first()?->fichier);
    }

    public function test_un_profil_incomplet_n_ouvre_que_l_ecran_d_inscription(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create();

        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.accueil'))->assertRedirect(route('stagiaire.inscription'));
        $this->get(route('stagiaire.inscription'))->assertOk()->assertSee('Mon identité');
    }

    public function test_le_sejour_reste_ferme_tant_que_l_inscription_n_est_pas_verifiee(): void
    {
        // Profil complet mais inscription soumise, pas encore vérifiée.
        $enAttente = $this->creerStagiaire($this->filles, [
            'statut_inscription' => StatutInscription::Soumise, 'inscription_verifiee_le' => null,
        ]);

        // Les routes du séjour renvoient toutes à l'écran d'inscription (verrou de route).
        foreach (['stagiaire.accueil', 'stagiaire.profil', 'stagiaire.dossier', 'stagiaire.hebergement',
            'stagiaire.paiements', 'stagiaire.repas', 'stagiaire.sorties', 'stagiaire.reclamations', 'stagiaire.documents'] as $route) {
            $this->actingAs($enAttente, 'stagiaire')->get(route($route))->assertRedirect(route('stagiaire.inscription'));
        }

        // Seule « Mon inscription » reste accessible, et le menu ne propose qu'elle.
        $this->actingAs($enAttente, 'stagiaire')->get(route('stagiaire.inscription'))->assertOk();
        $this->assertSame(['Mon inscription'], collect(Menu::stagiaire($enAttente))->flatMap(fn ($s) => $s['entrees'])->pluck('libelle')->all());
    }

    public function test_le_sejour_s_ouvre_une_fois_l_inscription_verifiee(): void
    {
        $verifie = $this->creerStagiaire($this->filles);

        $this->actingAs($verifie, 'stagiaire')->get(route('stagiaire.accueil'))->assertOk();

        // Le menu complet réapparaît : séjour et dossier compris.
        $libelles = collect(Menu::stagiaire($verifie))->flatMap(fn ($s) => $s['entrees'])->pluck('libelle');
        $this->assertTrue($libelles->contains('Hébergement') && $libelles->contains('Mon dossier') && $libelles->count() > 1);
    }

    /* ------------------------------------------------------------ Connexion */

    public function test_le_stagiaire_se_connecte_ensuite_avec_son_compte_internat(): void
    {
        $stagiaire = Stagiaire::factory()->create(['email' => 'salma@exemple.ma']);

        Livewire::test(Connexion::class, ['espace' => 'stagiaire'])
            ->set('espace', 'stagiaire')->set('email', 'salma@exemple.ma')->set('mot_de_passe', 'Mauvais-Motdepasse-9')
            ->call('connecter')->assertHasErrors('email');
        $this->assertFalse(Auth::guard('stagiaire')->check());

        Livewire::test(Connexion::class)
            ->set('espace', 'stagiaire')->set('email', 'salma@exemple.ma')->set('mot_de_passe', 'Motdepasse-Test-1')
            ->call('connecter')->assertHasNoErrors()->assertRedirect(route('stagiaire.accueil'));

        $this->assertTrue(Auth::guard('stagiaire')->check());
        $this->assertSame($stagiaire->id, Auth::guard('stagiaire')->id());
    }

    public function test_un_compte_suspendu_n_ouvre_pas_de_session(): void
    {
        Stagiaire::factory()->create(['email' => 'suspendu@exemple.ma', 'etat_compte' => EtatCompte::Suspendu, 'suspendu_le' => now()]);

        Livewire::test(Connexion::class)
            ->set('espace', 'stagiaire')->set('email', 'suspendu@exemple.ma')->set('mot_de_passe', 'Motdepasse-Test-1')
            ->call('connecter')
            ->assertSee('Votre compte INTERNAT est suspendu')
            ->assertNoRedirect();

        $this->assertFalse(Auth::guard('stagiaire')->check());
    }

    public function test_un_visiteur_est_renvoye_a_la_connexion_et_chacun_reste_dans_son_espace(): void
    {
        $this->get('/')->assertRedirect(route('connexion'));
        $this->get(route('stagiaire.accueil'))->assertRedirect(route('connexion'));

        // Un stagiaire n'ouvre pas l'espace du personnel : il est ramené au sien.
        $stagiaire = Stagiaire::factory()->create();
        $this->actingAs($stagiaire, 'stagiaire')->get('/')->assertRedirect(route('connexion'));
        $this->get(route('connexion'))->assertRedirect(route('stagiaire.accueil'));
        $this->get('/inscriptions')->assertRedirect(route('connexion'));
    }
}
