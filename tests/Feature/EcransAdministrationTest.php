<?php

namespace Tests\Feature;

use App\Domain\Audit\Journal;
use App\Domain\Reporting\Exports;
use App\Domain\Shared\Parametres as LectureParametres;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\ResultatVerification;
use App\Enums\RoleCode;
use App\Enums\StatutExport;
use App\Jobs\GenererExport;
use App\Livewire\Administration\Audit;
use App\Livewire\Administration\ComptesStagiaires;
use App\Livewire\Administration\Organisation;
use App\Livewire\Administration\Parametres;
use App\Livewire\Administration\RolesEtPermissions;
use App\Livewire\Administration\Utilisateurs;
use App\Livewire\Personnel\Rapports;
use App\Models\Batiment;
use App\Models\Etablissement;
use App\Models\Export;
use App\Models\Internat;
use App\Models\JournalAudit;
use App\Models\Parametre;
use App\Models\Region;
use App\Models\Utilisateur;
use App\Models\VerificationIdentite;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Administration et pilotage dans la nouvelle interface : comptes du personnel,
 * comptes des stagiaires, organisation, règles paramétrables, journal d'audit,
 * exports. Tout y est réservé au rôle qui en répond, et tout y est tracé.
 */
class EcransAdministrationTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private const MOT_DE_PASSE = 'Essai-Interne-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    /* ============================================================ Utilisateurs */

    public function test_l_administrateur_cree_un_compte_avec_le_perimetre_de_son_role(): void
    {
        $ecran = Livewire::actingAs($this->admin)->test(Utilisateurs::class)->assertSee($this->gestionnaireFilles->email)->call('creer');

        // Un gestionnaire sans internat, un mot de passe trop faible, une adresse déjà prise : refusés.
        $ecran->set('saisie.prenom', 'Salma')->set('saisie.nom', 'Bennani')->set('saisie.email', $this->chefDePole->email)
            ->set('saisie.role', RoleCode::Gestionnaire->value)->set('saisie.mot_de_passe', 'court')->call('enregistrer')
            ->assertHasErrors(['saisie.email', 'saisie.mot_de_passe', 'saisie.internat_id']);

        // Les niveaux étrangers au rôle sont remis à vide, même s'ils ont été renseignés.
        $ecran->set('saisie.email', 'Salma.Bennani@internat.test')->set('saisie.mot_de_passe', self::MOT_DE_PASSE)
            ->set('saisie.internat_id', (string) $this->filles->id)->set('saisie.region_id', (string) $this->region->id)
            ->call('enregistrer')->assertHasNoErrors()->assertSet('dialogue', null)->assertSee('salma.bennani@internat.test');

        $compte = Utilisateur::where('email', 'salma.bennani@internat.test')->sole();
        $this->assertSame([RoleCode::Gestionnaire, $this->filles->id, null, true], [$compte->roleCode(), $compte->internat_id, $compte->region_id, $compte->actif]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $compte->mot_de_passe_hash));

        // Le journal garde la création, jamais le mot de passe.
        $trace = JournalAudit::where('action', 'utilisateur.creation')->sole();
        $this->assertStringNotContainsString(self::MOT_DE_PASSE, json_encode($trace->details));

        // Changer de rôle change le niveau de périmètre ; le mot de passe laissé vide est conservé.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->call('modifier', $compte->id)->assertSet('saisie.email', 'salma.bennani@internat.test')
            ->set('saisie.role', RoleCode::DirRegionale->value)->call('enregistrer')->assertHasErrors('saisie.region_id')
            ->set('saisie.region_id', (string) $this->region->id)->call('enregistrer')->assertHasNoErrors();

        $compte->refresh()->load('roles');
        $this->assertSame([RoleCode::DirRegionale, null, $this->region->id], [$compte->roleCode(), $compte->internat_id, $compte->region_id]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $compte->mot_de_passe_hash));

        // Filtres de la liste.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->set('role', RoleCode::DirRegionale->value)
            ->assertSee($compte->email)->assertDontSee($this->gestionnaireFilles->email)
            ->set('role', '')->set('recherche', 'bennani')->assertSee($compte->email)->assertDontSee($this->chefDePole->email);
    }

    public function test_nul_ne_se_desactive_et_un_administrateur_actif_subsiste(): void
    {
        $ecran = Livewire::actingAs($this->admin)->test(Utilisateurs::class);

        // Son propre compte : ni désactivation, ni changement de rôle.
        $ecran->call('basculer', $this->admin->id);
        $this->assertTrue($this->admin->fresh()->actif);
        $ecran->call('modifier', $this->admin->id)->set('saisie.role', RoleCode::DirCentrale->value)->call('enregistrer');
        $this->assertSame(RoleCode::Admin, $this->admin->fresh()->roleCode());
        $ecran->call('fermerDialogue');

        // Un autre compte se désactive puis se réactive ; son historique reste.
        $ecran->call('basculer', $this->gestionnaireGarcons->id);
        $this->assertFalse($this->gestionnaireGarcons->fresh()->actif);
        $ecran->set('actif', '0')->assertSee($this->gestionnaireGarcons->email)->assertDontSee($this->chefDePole->email)->set('actif', '');
        $ecran->call('basculer', $this->gestionnaireGarcons->id);
        $this->assertTrue($this->gestionnaireGarcons->fresh()->actif);
        $this->assertSame(1, JournalAudit::where('action', 'utilisateur.desactivation')->count());

        // Un second administrateur ne peut pas désactiver le dernier autre… tant qu'il reste seul actif.
        $second = Utilisateur::factory()->role(RoleCode::Admin)->create();
        Livewire::actingAs($second)->test(Utilisateurs::class)->call('basculer', $this->admin->id);
        $this->assertFalse($this->admin->fresh()->actif);
        Livewire::actingAs($second)->test(Utilisateurs::class)->call('modifier', $second->id)->set('saisie.actif', false)->call('enregistrer');
        $this->assertTrue($second->fresh()->actif);
    }

    public function test_l_administration_est_fermee_aux_autres_roles(): void
    {
        foreach (['utilisateurs', 'permissions', 'comptes-stagiaires', 'organisation', 'parametres', 'audit'] as $ecran) {
            $this->connecter($this->chefDePole)->get("/administration/{$ecran}")->assertForbidden();
            $this->connecter($this->gestionnaireFilles)->get("/administration/{$ecran}")->assertForbidden();
            $this->connecter($this->admin)->get("/administration/{$ecran}")->assertOk();
        }

        // Appeler l'action sans passer par l'écran ne contourne rien.
        Livewire::actingAs($this->chefDePole, 'web')->test(Utilisateurs::class)->assertForbidden();
        Livewire::actingAs($this->directeur, 'web')->test(Parametres::class)->assertForbidden();

        // L'administrateur gère les comptes, pas les dossiers ni les paiements.
        $this->connecter($this->admin)->get('/dossiers')->assertForbidden();
        $this->connecter($this->admin)->get('/paiements')->assertForbidden();

        $this->connecter($this->admin)->get('/administration/permissions')->assertOk()->assertSee('Matrice des permissions')->assertSee('inscriptions.verifier');
        Livewire::actingAs($this->admin, 'web')->test(RolesEtPermissions::class)->assertSee('Son internat')->assertSee('National');
    }

    /* ====================================================== Comptes stagiaires */

    public function test_un_compte_stagiaire_se_suspend_se_retablit_sans_jamais_toucher_au_mot_de_passe(): void
    {
        Notification::fake();
        $stagiaire = $this->creerStagiaire($this->filles);
        $empreinte = $stagiaire->mot_de_passe_hash;

        $ecran = Livewire::actingAs($this->admin)->test(ComptesStagiaires::class)->assertSee($stagiaire->email)->assertSee('Vérifiée');

        $ecran->call('suspendre', $stagiaire->id, 'non')->assertHasErrors('motif');
        $this->assertSame(EtatCompte::Actif, $stagiaire->fresh()->etat_compte);

        $ecran->call('suspendre', $stagiaire->id, 'Usurpation signalée par la scolarité.')->assertHasNoErrors()->assertSee('Usurpation signalée');
        $stagiaire->refresh();
        $this->assertSame([EtatCompte::Suspendu, 'Usurpation signalée par la scolarité.'], [$stagiaire->etat_compte, $stagiaire->motif_suspension]);

        // Suspendu : la session ouverte est refusée à la requête suivante, et aucun lien n'est envoyé.
        $this->connecter($stagiaire)->get('/stagiaire')->assertRedirect(route('connexion'))->assertSessionHas('danger');
        $this->assertGuest('stagiaire');
        Livewire::actingAs($this->admin, 'web')->test(ComptesStagiaires::class)->call('envoyerLien', $stagiaire->id);
        Notification::assertNothingSent();

        $ecran = Livewire::actingAs($this->admin, 'web')->test(ComptesStagiaires::class)->set('etat', EtatCompte::Suspendu->value)->assertSee($stagiaire->email);
        $ecran->call('retablir', $stagiaire->id)->assertHasNoErrors();
        $this->assertSame([EtatCompte::Actif, null], [$stagiaire->fresh()->etat_compte, $stagiaire->fresh()->motif_suspension]);

        $ecran->call('envoyerLien', $stagiaire->id)->assertHasNoErrors();
        Notification::assertSentTo($stagiaire, ReinitialisationMotDePasse::class);

        // À aucun moment l'administrateur n'a fixé le mot de passe.
        $this->assertSame($empreinte, $stagiaire->fresh()->mot_de_passe_hash);
        $this->assertSame(['stagiaire.suspension', 'stagiaire.retablissement', 'stagiaire.reinitialisation_envoyee'],
            JournalAudit::where('action', 'like', 'stagiaire.%')->orderBy('id')->pluck('action')->all());

        // Le chef de pôle vérifie les inscriptions ; il n'administre pas les comptes.
        Livewire::actingAs($this->chefDePole, 'web')->test(ComptesStagiaires::class)->assertForbidden();
    }

    public function test_les_verifications_myway_ne_sont_plus_qu_une_archive(): void
    {
        // Sans archive, l'onglet n'apparaît pas.
        Livewire::actingAs($this->admin)->test(ComptesStagiaires::class)->assertDontSee('Archive MyWay');

        VerificationIdentite::create([
            'fournisseur' => 'connecteur', 'methode' => 'identifiants', 'simulee' => false, 'resultat' => ResultatVerification::EfpInconnu,
            'email' => 'ancien@ofppt-edu.test', 'nom_efp' => 'ISTA inconnu',
        ]);

        Livewire::actingAs($this->admin)->test(ComptesStagiaires::class)->assertSee('Archive MyWay')->call('voir', 'archive')
            ->assertSee('ancien@ofppt-edu.test')->assertSee('Établissement non référencé')->assertSee('Archive en lecture seule')->assertDontSee('Clore l’examen');
    }

    /* ============================================================ Organisation */

    public function test_l_organisation_se_tient_niveau_par_niveau(): void
    {
        $ecran = Livewire::actingAs($this->admin)->test(Organisation::class)->assertSee('Établissement de test')->assertSee('T100');

        // Région, puis établissement : code et nom EFP sont uniques.
        $ecran->call('ajouter', 'regions')->call('enregistrer')->assertHasErrors(['saisie.code', 'saisie.nom'])
            ->set('saisie.code', 'R99')->set('saisie.nom', 'Région d’essai')->call('enregistrer')->assertHasNoErrors();
        $region = Region::where('code', 'R99')->sole();

        $ecran->call('ajouter', 'etablissements')->set('saisie.region_id', (string) $region->id)->set('saisie.code_efp', 'T100')->set('saisie.nom_efp', 'ISTA d’essai')
            ->call('enregistrer')->assertHasErrors('saisie.code_efp')
            ->set('saisie.code_efp', 'T200')->call('enregistrer')->assertHasNoErrors();
        $etablissement = Etablissement::where('code_efp', 'T200')->sole();
        $this->assertSame($region->id, $etablissement->region_id);

        // Un établissement a au plus un internat par genre.
        $ecran->call('ajouter', 'internats')->set('saisie.etablissement_id', (string) $this->etablissement->id)->set('saisie.genre', Genre::Fille->value)->set('saisie.nom', 'Doublon')
            ->call('enregistrer')->assertHasErrors('saisie.genre')
            ->set('saisie.etablissement_id', (string) $etablissement->id)->set('saisie.nom', 'Internat d’essai')->call('enregistrer')->assertHasNoErrors();
        $internat = Internat::where('nom', 'Internat d’essai')->sole();

        // Le genre d'un internat doté d'un référentiel ne se change plus.
        Batiment::factory()->create(['internat_id' => $this->filles->id]);
        $ecran->call('modifier', 'internats', $this->filles->id)->assertSet('saisie.genre', Genre::Fille->value)->set('saisie.genre', Genre::Garcon->value)->call('enregistrer');
        $this->assertSame(Genre::Fille, $this->filles->fresh()->genre);
        $ecran->call('fermerDialogue');

        // Suppression : refusée tant que des données sont rattachées, permise sinon.
        $ecran->call('supprimer', 'regions', $region->id);
        $this->assertNotNull($region->fresh());
        $ecran->call('supprimer', 'internats', $internat->id)->call('supprimer', 'etablissements', $etablissement->id)->call('supprimer', 'regions', $region->id);
        $this->assertNull($region->fresh());
        $this->assertSame(3, JournalAudit::where('action', 'organisation.suppression')->count());

        $ecran->call('ajouter', 'inconnu')->assertNotFound();
    }

    /* ============================================================== Paramètres */

    public function test_une_regle_parametrable_se_modifie_se_confirme_et_laisse_une_trace(): void
    {
        $ecran = Livewire::actingAs($this->admin)->test(Parametres::class)->assertSee('règle(s) provisoire(s)')->assertSee('Inscription en formation')
            ->assertSet('valeurs.confirmation__delai_admis_jours', 10);

        // Valeurs hors bornes ou hors liste : refusées.
        $ecran->set('valeurs.confirmation__delai_admis_jours', 9999)->call('enregistrer', 'confirmation.delai_admis_jours')->assertHasErrors('valeurs.confirmation__delai_admis_jours');
        $ecran->set('valeurs.restauration__heure_limite_reservation', '25h')->call('enregistrer', 'restauration.heure_limite_reservation')->assertHasErrors('valeurs.restauration__heure_limite_reservation');
        $ecran->set('valeurs.classement__departage', 'tirage_au_sort')->call('enregistrer', 'classement.departage')->assertHasErrors('valeurs.classement__departage');
        $this->assertSame(10, app(LectureParametres::class)->entier('confirmation.delai_admis_jours'));
        $this->assertSame(0, JournalAudit::where('action', 'parametre.modification')->count());

        $ecran->set('valeurs.confirmation__delai_admis_jours', 7)->call('enregistrer', 'confirmation.delai_admis_jours')->assertHasNoErrors();
        $this->assertSame(7, app(LectureParametres::class)->entier('confirmation.delai_admis_jours'));

        // Confirmer la règle de vérification des inscriptions : elle n'est plus provisoire.
        $ecran->set('valeurs.inscription__depot_exige_verification', true)->set('confirmees.inscription__depot_exige_verification', true)
            ->call('enregistrer', 'inscription.depot_exige_verification')->assertHasNoErrors();
        $regle = Parametre::findOrFail('inscription.depot_exige_verification');
        $this->assertSame([true, false, $this->admin->id], [$regle->valeur, $regle->a_valider, $regle->modifie_par]);

        $trace = JournalAudit::where('action', 'parametre.modification')->orderBy('id')->first();
        // MySQL range les clés d'un JSON à sa façon : l'ordre n'est pas comparé.
        $this->assertEquals(['cle' => 'confirmation.delai_admis_jours', 'avant' => 10, 'apres' => 7, 'a_valider' => false], $trace->details);

        $ecran->call('enregistrer', 'cle.inconnue')->assertNotFound();
    }

    /* ================================================================== Audit */

    public function test_le_journal_d_audit_se_consulte_et_se_filtre_sans_se_modifier(): void
    {
        $journal = app(Journal::class);
        $journal->consigner('paiement.autorisation', 'paiement', 12, ['montant' => '150.00'], $this->chefDePole);
        $journal->consigner('stagiaire.suspension', 'stagiaire', 3, ['motif' => 'Essai'], $this->admin);
        $premiere = JournalAudit::where('action', 'paiement.autorisation')->sole();

        $ecran = Livewire::actingAs($this->admin)->test(Audit::class)->assertSee('paiement.autorisation')->assertSee('stagiaire.suspension')->assertSee($this->chefDePole->nomComplet());

        $ecran->set('action', 'paiement')->assertSee('paiement.autorisation')->assertDontSee('stagiaire.suspension');
        $ecran->set('action', '')->set('entite', 'stagiaire')->assertSee('stagiaire.suspension')->assertDontSee('paiement.autorisation');
        $ecran->set('entite', '')->set('du', now()->addDay()->toDateString())->assertSee('Aucune entrée ne correspond aux filtres');
        $ecran->call('effacerFiltres')->assertSet('du', '')->assertSee('paiement.autorisation');

        $ecran->call('detailler', $premiere->id)->assertSet('dialogue', 'detail')->assertSee('150.00');

        // L'écran n'offre aucune action d'écriture.
        $this->assertSame([], array_values(array_filter(get_class_methods(Audit::class), fn (string $m) => preg_match('/^(supprimer|modifier|enregistrer|effacer(?!Filtres))/', $m) === 1)));
        Livewire::actingAs($this->directionCentrale, 'web')->test(Audit::class)->assertForbidden();
    }

    /* ================================================================ Rapports */

    public function test_un_export_se_demande_dans_son_perimetre_et_ne_se_telecharge_que_par_son_demandeur(): void
    {
        Queue::fake();
        $campagne = $this->creerCampagne($this->filles);
        $ailleurs = $this->creerCampagne($this->ailleurs);

        // Les contenus proposés suivent les permissions du rôle.
        $ecran = Livewire::actingAs($this->chefDePole)->test(Rapports::class)->assertSee('Liste des admis')->assertSee('Suivi des paiements');
        Livewire::actingAs($this->restauration)->test(Rapports::class)->assertSee('Effectifs de restauration')->assertDontSee('Liste des admis');

        $ecran = Livewire::actingAs($this->chefDePole)->test(Rapports::class);
        // La liste des admis exige une campagne ; une campagne hors périmètre n'existe pas pour ce compte.
        $ecran->set('type', 'liste_admis')->call('demander')->assertHasErrors('campagne');
        $ecran->set('campagne', (string) $ailleurs->id)->call('demander')->assertNotFound();
        $this->assertSame(0, Export::count());

        Livewire::actingAs($this->chefDePole)->test(Rapports::class)->set('type', 'effectifs_inconnus')->call('demander')->assertHasErrors('type');
        Livewire::actingAs($this->restauration)->test(Rapports::class)->set('type', 'liste_admis')->set('campagne', (string) $campagne->id)->call('demander')->assertHasErrors('type');

        Livewire::actingAs($this->chefDePole)->test(Rapports::class)->set('type', 'liste_admis')->set('campagne', (string) $campagne->id)->set('format', 'csv')
            ->call('demander')->assertHasNoErrors()->assertSee('En attente');
        $export = Export::sole();
        $this->assertSame([$this->chefDePole->id, 'liste_admis', 'csv', ['campagne_id' => $campagne->id]], [$export->utilisateur_id, $export->type, $export->format, $export->filtres]);
        Queue::assertPushed(GenererExport::class);

        // Pas encore produit : rien à télécharger.
        $this->connecter($this->chefDePole)->get(route('rapports.fichier', $export))->assertNotFound();

        $this->agirEnTantQue($this->chefDePole);
        app(Exports::class)->generer($export);
        $export->refresh();
        $this->assertSame(StatutExport::Termine, $export->statut);
        Storage::disk('prive')->assertExists($export->fichier);

        $this->connecter($this->chefDePole)->get(route('rapports.fichier', $export))->assertOk()->assertHeader('content-disposition');
        // Un autre compte, même habilité à exporter, ne télécharge pas l'export d'autrui.
        $this->connecter($this->gestionnaireFilles)->get(route('rapports.fichier', $export))->assertNotFound();
        $this->connecter($this->admin)->get('/rapports')->assertForbidden();

        // Fichier expiré et purgé : la ligne reste, le téléchargement disparaît.
        $export->forceFill(['expire_le' => now()->subDay()])->save();
        app(Exports::class)->purgerExpires();
        Livewire::actingAs($this->chefDePole, 'web')->test(Rapports::class)->assertSee('Fichier expiré');
        $this->connecter($this->chefDePole)->get(route('rapports.fichier', $export))->assertNotFound();
    }
}
