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
use App\Livewire\Stagiaire\MonInscription;
use App\Models\Campagne;
use App\Models\JournalAudit;
use App\Models\Stagiaire;
use App\Notifications\VerificationAdresse;
use App\Support\AnneeScolaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
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

    /* ---------------------------------------------------- Création du compte */

    public function test_un_stagiaire_cree_son_compte_et_arrive_sur_son_inscription(): void
    {
        Livewire::test(Inscription::class)
            ->set('email', 'Salma.ElAmrani@Exemple.ma')
            ->set('mot_de_passe', self::MOT_DE_PASSE)
            ->set('mot_de_passe_confirmation', self::MOT_DE_PASSE)
            ->call('creer')
            ->assertHasNoErrors()
            ->assertRedirect(route('stagiaire.inscription'));

        $stagiaire = Stagiaire::where('email', 'salma.elamrani@exemple.ma')->sole();

        $this->assertSame(EtatCompte::Actif, $stagiaire->etat_compte);
        $this->assertSame(StatutInscription::NonDeclaree, $stagiaire->statut_inscription);
        $this->assertNotNull($stagiaire->inscrit_le);
        $this->assertNull($stagiaire->cef);
        $this->assertNull($stagiaire->inscription_verifiee_le);
        $this->assertStringStartsWith('$argon2id$', $stagiaire->mot_de_passe_hash);
        $this->assertTrue(Auth::guard('stagiaire')->check());
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertTrue(JournalAudit::where('action', 'stagiaire.inscription')->where('stagiaire_id', $stagiaire->id)->exists());
    }

    public function test_le_mot_de_passe_doit_respecter_la_politique_et_etre_confirme(): void
    {
        $composant = Livewire::test(Inscription::class)->set('email', 'nouveau@exemple.ma');

        $composant->set('mot_de_passe', 'court')->set('mot_de_passe_confirmation', 'court')->call('creer')->assertHasErrors('mot_de_passe');
        $composant->set('mot_de_passe', self::MOT_DE_PASSE)->set('mot_de_passe_confirmation', 'Autre-Chose-2026')->call('creer')->assertHasErrors('mot_de_passe');
        $composant->set('mot_de_passe', 'Nouveau-2026-secret')->set('mot_de_passe_confirmation', 'Nouveau-2026-secret')->call('creer')
            ->assertHasErrors(['mot_de_passe' => 'Le mot de passe ne doit pas contenir votre adresse e-mail.']);

        $this->assertSame(0, Stagiaire::count());
    }

    public function test_une_adresse_deja_prise_est_refusee_sans_rien_reveler_du_compte_existant(): void
    {
        $existant = Stagiaire::factory()->create(['email' => 'deja@exemple.ma']);

        Livewire::test(Inscription::class)
            ->set('email', 'DEJA@exemple.ma')
            ->set('mot_de_passe', self::MOT_DE_PASSE)
            ->set('mot_de_passe_confirmation', self::MOT_DE_PASSE)
            ->call('creer')
            ->assertHasErrors('email')
            ->assertDontSee($existant->nom)
            ->assertNoRedirect();

        $this->assertSame(1, Stagiaire::count());
        $this->assertFalse(Auth::guard('stagiaire')->check());
        $this->assertTrue(password_verify('Motdepasse-Test-1', $existant->fresh()->mot_de_passe_hash));
    }

    public function test_la_creation_de_compte_est_limitee_en_debit(): void
    {
        // Cinq essais par minute sur une même adresse et depuis un même poste ; le sixième est refusé.
        Stagiaire::factory()->create(['email' => 'cible@exemple.ma']);

        foreach (range(1, 5) as $essai) {
            Livewire::test(Inscription::class)->set('email', 'cible@exemple.ma')
                ->set('mot_de_passe', self::MOT_DE_PASSE)->set('mot_de_passe_confirmation', self::MOT_DE_PASSE)->call('creer')
                ->assertHasErrors('email');
        }

        Livewire::test(Inscription::class)->set('email', 'cible@exemple.ma')
            ->set('mot_de_passe', self::MOT_DE_PASSE)->set('mot_de_passe_confirmation', self::MOT_DE_PASSE)->call('creer')
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

    public function test_avec_un_serveur_d_envoi_le_stagiaire_confirme_son_adresse_par_un_lien_signe(): void
    {
        config(['mail.default' => 'smtp', 'internat.inscription.verifier_adresse' => null]);
        Notification::fake();

        Livewire::test(Inscription::class)
            ->set('email', 'a.confirmer@exemple.ma')
            ->set('mot_de_passe', self::MOT_DE_PASSE)
            ->set('mot_de_passe_confirmation', self::MOT_DE_PASSE)
            ->call('creer')
            ->assertHasNoErrors();

        $stagiaire = Stagiaire::where('email', 'a.confirmer@exemple.ma')->sole();
        Notification::assertSentTo($stagiaire, VerificationAdresse::class);

        // Tant que l'adresse n'est pas confirmée, tout l'espace ramène à l'écran qui le demande.
        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.inscription'))->assertRedirect(route('stagiaire.adresse'));
        $this->get(route('stagiaire.adresse'))->assertOk()->assertSee('Confirmez votre adresse e-mail');

        // Un lien falsifié, ou établi pour une autre adresse, est refusé.
        $lien = VerificationAdresse::lien($stagiaire);
        $this->get($lien.'x')->assertForbidden();
        $this->get(str_replace(sha1($stagiaire->email), sha1('autre@exemple.ma'), $lien))->assertForbidden();
        $this->assertNull($stagiaire->fresh()->email_verifie_le);

        $this->get($lien)->assertRedirect(route('stagiaire.inscription'));
        $this->assertNotNull($stagiaire->fresh()->email_verifie_le);
        $this->actingAs($stagiaire->fresh(), 'stagiaire')->get(route('stagiaire.inscription'))->assertOk();

        // Une adresse confirmée ne dit rien de l'inscription en formation.
        $this->assertSame(StatutInscription::NonDeclaree, $stagiaire->fresh()->statut_inscription);
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
        $campagne = $this->creerCampagne($this->filles);
        $this->declarerFormation($ecran, $campagne)->call('enregistrerFormation')->assertHasNoErrors()->assertSet('etape', 'verification');

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
            ->assertSet('etape', 'verification');
        $this->assertSame($this->etablissement->id, $stagiaire->fresh()->etablissement_id);
    }

    public function test_un_profil_incomplet_n_ouvre_que_l_ecran_d_inscription(): void
    {
        $stagiaire = Stagiaire::factory()->nouveau()->create();

        $this->actingAs($stagiaire, 'stagiaire')->get(route('stagiaire.accueil'))->assertRedirect(route('stagiaire.inscription'));
        $this->get(route('stagiaire.inscription'))->assertOk()->assertSee('Mon identité');
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
