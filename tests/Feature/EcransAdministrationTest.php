<?php

namespace Tests\Feature;

use App\Domain\Audit\Journal;
use App\Domain\Identity\ComptesPersonnel;
use App\Domain\Reporting\Exports;
use App\Domain\Shared\Parametres as LectureParametres;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\ResultatVerification;
use App\Enums\RoleCode;
use App\Enums\StatutExport;
use App\Enums\StatutInscription;
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
use App\Models\Stagiaire;
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

        // Le gestionnaire est rattaché au dernier niveau parcouru, l'internat ; les niveaux parents ne sont pas conservés.
        $complexe = $this->etablissement->complexe;
        $ecran->set('saisie.email', 'Salma.Bennani@internat.test')->set('saisie.mot_de_passe', self::MOT_DE_PASSE)
            ->set('saisie.region_id', (string) $this->region->id)->set('saisie.province_id', (string) $complexe->province_id)
            ->set('saisie.complexe_id', (string) $complexe->id)->set('saisie.etablissement_id', (string) $this->etablissement->id)
            ->set('saisie.internat_id', (string) $this->filles->id)
            ->call('enregistrer')->assertHasNoErrors()->assertSet('dialogue', null)->assertSee('salma.bennani@internat.test');

        $compte = Utilisateur::where('email', 'salma.bennani@internat.test')->sole();
        $this->assertSame([RoleCode::Gestionnaire, $this->filles->id, null, true], [$compte->roleCode(), $compte->internat_id, $compte->region_id, $compte->actif]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $compte->mot_de_passe_hash));

        // Le journal garde la création, jamais le mot de passe.
        $trace = JournalAudit::where('action', 'utilisateur.creation')->sole();
        $this->assertStringNotContainsString(self::MOT_DE_PASSE, json_encode($trace->details));

        // Changer de rôle change le niveau de périmètre (la région parcourue reste choisie) ; le mot de passe laissé vide est conservé.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->call('modifier', $compte->id)->assertSet('saisie.email', 'salma.bennani@internat.test')
            ->set('saisie.role', RoleCode::DirRegionale->value)->assertSet('saisie.internat_id', '')->assertSet('saisie.province_id', '')
            ->set('saisie.region_id', '')->call('enregistrer')->assertHasErrors('saisie.region_id')
            ->set('saisie.region_id', (string) $this->region->id)->call('enregistrer')->assertHasNoErrors();

        $compte->refresh()->load('roles');
        $this->assertSame([RoleCode::DirRegionale, null, $this->region->id], [$compte->roleCode(), $compte->internat_id, $compte->region_id]);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $compte->mot_de_passe_hash));

        // Filtres de la liste.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->set('role', RoleCode::DirRegionale->value)
            ->assertSee($compte->email)->assertDontSee($this->gestionnaireFilles->email)
            ->set('role', '')->set('recherche', 'bennani')->assertSee($compte->email)->assertDontSee($this->chefDePole->email);
    }

    public function test_le_formulaire_de_compte_parcourt_la_hierarchie_du_role(): void
    {
        $complexe = $this->etablissement->complexe;
        $province = $complexe->province;
        $autreRegion = $this->ailleurs->etablissement->complexe->province->region;

        $ecran = Livewire::actingAs($this->admin)->test(Utilisateurs::class)->call('creer')
            ->set('saisie.prenom', 'Nora')->set('saisie.nom', 'Alami')->set('saisie.email', 'nora.alami@internat.test')
            ->set('saisie.mot_de_passe', self::MOT_DE_PASSE);

        // Gestionnaire : région, province, complexe, établissement, internat ; chaque liste filtrée par son parent.
        $ecran->assertViewHas('niveaux', ComptesPersonnel::HIERARCHIE['GESTIONNAIRE'])
            ->assertViewHas('listes', fn (array $l) => $l['region_id']->has($this->region->id) && $l['province_id']->isEmpty())
            ->set('saisie.region_id', (string) $this->region->id)
            ->assertViewHas('listes', fn (array $l) => $l['province_id']->keys()->all() === [$province->id])
            ->set('saisie.province_id', (string) $province->id)->set('saisie.complexe_id', (string) $complexe->id)
            ->set('saisie.etablissement_id', (string) $this->etablissement->id)
            ->assertViewHas('listes', fn (array $l) => $l['internat_id']->keys()->sort()->values()->all() === collect([$this->filles->id, $this->garcons->id])->sort()->values()->all())
            ->set('saisie.internat_id', (string) $this->garcons->id);

        // Changer un niveau efface ceux d'en dessous.
        $ecran->set('saisie.region_id', (string) $autreRegion->id)
            ->assertSet('saisie.province_id', '')->assertSet('saisie.complexe_id', '')->assertSet('saisie.etablissement_id', '')->assertSet('saisie.internat_id', '')
            ->call('enregistrer')->assertHasErrors(['saisie.province_id', 'saisie.complexe_id', 'saisie.etablissement_id', 'saisie.internat_id']);

        // Changer de rôle ne garde que les niveaux qu'il demande : le chef de pôle s'arrête à l'établissement.
        $ecran->set('saisie.region_id', (string) $this->region->id)->set('saisie.province_id', (string) $province->id)
            ->set('saisie.complexe_id', (string) $complexe->id)->set('saisie.etablissement_id', (string) $this->etablissement->id)
            ->set('saisie.internat_id', (string) $this->filles->id)
            ->set('saisie.role', RoleCode::ChefPole->value)->assertViewHas('niveaux', ComptesPersonnel::HIERARCHIE['CHEF_POLE'])
            ->assertSet('saisie.internat_id', '')->assertSet('saisie.etablissement_id', (string) $this->etablissement->id);

        // Le directeur s'arrête au complexe / CMC et y est rattaché directement.
        $ecran->set('saisie.role', RoleCode::Directeur->value)->assertSet('saisie.etablissement_id', '')
            ->call('enregistrer')->assertHasNoErrors()->assertSet('dialogue', null);
        $directeur = Utilisateur::where('email', 'nora.alami@internat.test')->sole();
        $this->assertSame([RoleCode::Directeur, $complexe->id, null, null], [$directeur->roleCode(), $directeur->complexe_id, $directeur->etablissement_id, $directeur->internat_id]);

        // À l'ouverture d'une fiche, la hiérarchie se reconstitue depuis le rattachement actuel.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->call('modifier', $this->gestionnaireFilles->id)
            ->assertSet('saisie.region_id', (string) $this->region->id)->assertSet('saisie.province_id', (string) $province->id)
            ->assertSet('saisie.complexe_id', (string) $complexe->id)->assertSet('saisie.etablissement_id', (string) $this->etablissement->id)
            ->assertSet('saisie.internat_id', (string) $this->filles->id)
            // Seul le rattachement change ; le reste du compte est conservé.
            ->set('saisie.internat_id', (string) $this->garcons->id)->call('enregistrer')->assertHasNoErrors();
        $this->gestionnaireFilles->refresh()->load('roles');
        $this->assertSame([$this->garcons->id, RoleCode::Gestionnaire], [$this->gestionnaireFilles->internat_id, $this->gestionnaireFilles->roleCode()]);

        // Un directeur rattaché autrefois à un établissement retrouve le complexe de cet établissement.
        Livewire::actingAs($this->admin)->test(Utilisateurs::class)->call('modifier', $this->directeur->id)
            ->assertSet('saisie.complexe_id', (string) $complexe->id)->assertSet('saisie.etablissement_id', '');

        // Côté serveur : un rattachement étranger à la hiérarchie transmise est refusé.
        $this->agirEnTantQue($this->admin);
        $intrus = ['nom' => 'Intrus', 'prenom' => 'Test', 'email' => 'intrus@internat.test', 'mot_de_passe' => self::MOT_DE_PASSE, 'role' => 'GESTIONNAIRE'];
        $this->assertRegle('hierarchie_incoherente', fn () => app(ComptesPersonnel::class)->creer($intrus + [
            'region_id' => $autreRegion->id, 'internat_id' => $this->filles->id,
        ], $this->admin));

        // Et nul ne confie une structure hors de son propre périmètre.
        $this->chefDePole->givePermissionTo('utilisateurs.gerer');
        $this->assertRegle('hors_perimetre', fn () => app(ComptesPersonnel::class)->creer($intrus + ['internat_id' => $this->ailleurs->id], $this->chefDePole));
        Livewire::actingAs($this->chefDePole)->test(Utilisateurs::class)->call('creer')
            ->assertViewHas('listes', fn (array $l) => $l['region_id']->keys()->all() === [$this->region->id]);
    }

    public function test_les_comptes_se_filtrent_niveau_par_niveau_de_la_hierarchie(): void
    {
        $complexe = $this->etablissement->complexe;
        $province = $complexe->province;
        $directeurDuComplexe = Utilisateur::factory()->directeur($this->etablissement->id)->create();
        $directeurDuComplexe->forceFill(['etablissement_id' => null, 'complexe_id' => $complexe->id])->save();
        $autreRegion = $this->ailleurs->etablissement->complexe->province->region;

        $ecran = Livewire::actingAs($this->admin)->test(Utilisateurs::class)
            ->assertViewHas('filtre', fn (array $f) => $f['regions']->has($this->region->id) && $f['provinces']->isEmpty());

        // Région : sa direction régionale et tout ce qui en dépend, ni les autres régions ni les comptes nationaux.
        $ecran->set('region', (string) $this->region->id)
            ->assertViewHas('filtre', fn (array $f) => $f['provinces']->keys()->all() === [$province->id])
            ->assertSee($this->directionRegionale->email)->assertSee($directeurDuComplexe->email)
            ->assertSee($this->chefDePole->email)->assertSee($this->gestionnaireFilles->email)
            ->assertDontSee($this->gestionnaireAilleurs->email)->assertDontSee($this->directionCentrale->email);

        // Province puis complexe : la direction régionale, plus large, sort de la liste.
        $ecran->set('province', (string) $province->id)
            ->assertDontSee($this->directionRegionale->email)->assertSee($directeurDuComplexe->email)
            ->set('complexe', (string) $complexe->id)
            ->assertViewHas('filtre', fn (array $f) => $f['etablissements']->has($this->etablissement->id))
            ->assertSee($directeurDuComplexe->email)->assertSee($this->gestionnaireGarcons->email);

        // Établissement : ses chefs de pôle, responsables restauration et gestionnaires d'internat.
        $ecran->set('etablissement', (string) $this->etablissement->id)
            ->assertDontSee($directeurDuComplexe->email)
            ->assertSee($this->chefDePole->email)->assertSee($this->restauration->email)->assertSee($this->gestionnaireFilles->email);

        // Les filtres existants s'ajoutent à la hiérarchie.
        $ecran->set('role', RoleCode::Gestionnaire->value)
            ->assertSee($this->gestionnaireFilles->email)->assertDontSee($this->chefDePole->email)
            ->set('recherche', $this->gestionnaireGarcons->email)
            ->assertSee($this->gestionnaireGarcons->email)->assertDontSee($this->gestionnaireFilles->email);

        // Changer un parent efface les niveaux en dessous.
        $ecran->set('complexe', '')->assertSet('etablissement', '')
            ->set('region', (string) $autreRegion->id)
            ->assertSet('province', '')->assertSet('complexe', '')->assertSet('etablissement', '');

        // Un niveau qui n'appartient pas à son parent (adresse modifiée à la main) est ignoré.
        Livewire::actingAs($this->admin)->withQueryParams(['region' => (string) $autreRegion->id, 'province' => (string) $province->id])
            ->test(Utilisateurs::class)->assertSet('province', '')->assertSee($this->gestionnaireAilleurs->email);

        $ecran->call('effacerFiltres')->assertSet('region', '')->assertSet('role', '')->assertSee($this->directionCentrale->email);
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

    public function test_les_comptes_stagiaires_se_filtrent_par_la_hierarchie_sans_creation_possible(): void
    {
        $complexe = $this->etablissement->complexe;
        $ici = $this->creerStagiaire($this->filles);
        $ailleurs = $this->creerStagiaire($this->ailleurs);
        $sansEtablissement = Stagiaire::factory()->create(['etablissement_id' => null, 'cef' => null, 'nom' => null, 'prenom' => null, 'nom_filiere' => null, 'statut_inscription' => StatutInscription::NonDeclaree]);
        $autreRegion = $this->ailleurs->etablissement->complexe->province->region;

        $ecran = Livewire::actingAs($this->admin)->test(ComptesStagiaires::class)
            ->assertSee($ici->email)->assertSee($ailleurs->email)->assertSee($sansEtablissement->email)
            // Aucun ajout : le stagiaire crée lui-même son compte en soumettant son inscription.
            ->assertDontSee('Nouveau compte')
            ->assertViewHas('filtre', fn (array $f) => $f['regions']->has($this->region->id) && $f['provinces']->isEmpty());

        // Chaque niveau resserre la liste sur les stagiaires des établissements qu'il couvre.
        $ecran->set('region', (string) $this->region->id)
            ->assertSee($ici->email)->assertDontSee($ailleurs->email)->assertDontSee($sansEtablissement->email)
            ->set('province', (string) $complexe->province_id)->set('complexe', (string) $complexe->id)
            ->assertViewHas('filtre', fn (array $f) => $f['etablissements']->keys()->all() === [$this->etablissement->id])
            ->set('etablissement', (string) $this->etablissement->id)->assertSee($ici->email)->assertDontSee($ailleurs->email);

        // Les filtres existants s'y ajoutent.
        $ecran->set('etat', EtatCompte::Suspendu->value)->assertDontSee($ici->email)->set('etat', '')
            ->set('recherche', $ailleurs->email)->assertDontSee($ici->email)->assertDontSee($ailleurs->email)->set('recherche', '');

        // Changer un parent efface les niveaux en dessous ; tout s'efface d'un geste.
        $ecran->set('region', (string) $autreRegion->id)
            ->assertSet('province', '')->assertSet('complexe', '')->assertSet('etablissement', '')
            ->assertSee($ailleurs->email)->assertDontSee($ici->email)
            ->call('effacerFiltres')->assertSet('region', '')->assertSee($sansEtablissement->email);
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

        // La hiérarchie est descendante : province sous la région, complexe sous la province.
        $ecran->call('ajouter', 'provinces')->set('saisie.region_id', (string) $region->id)->set('saisie.code', 'P99')->set('saisie.nom', 'Province d’essai')
            ->call('enregistrer')->assertHasNoErrors();
        $province = \App\Models\Province::where('code', 'P99')->sole();

        $ecran->call('ajouter', 'complexes')->set('saisie.province_id', (string) $province->id)->set('saisie.code', 'C99')->set('saisie.nom', 'Complexe d’essai')
            ->call('enregistrer')->assertHasNoErrors();
        $complexe = \App\Models\Complexe::where('code', 'C99')->sole();

        $ecran->call('ajouter', 'etablissements')->set('saisie.complexe_id', (string) $complexe->id)->set('saisie.code_efp', 'T100')->set('saisie.nom_efp', 'ISTA d’essai')
            ->call('enregistrer')->assertHasErrors('saisie.code_efp')
            ->set('saisie.code_efp', 'T200')->call('enregistrer')->assertHasNoErrors();
        $etablissement = Etablissement::where('code_efp', 'T200')->sole();
        $this->assertSame($complexe->id, $etablissement->complexe_id);

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
        // La hiérarchie se démonte de bas en haut : internat → établissement → complexe → province → région.
        $ecran->call('supprimer', 'regions', $region->id);
        $this->assertNotNull($region->fresh());
        $ecran->call('supprimer', 'internats', $internat->id)
            ->call('supprimer', 'etablissements', $etablissement->id)
            ->call('supprimer', 'complexes', $complexe->id)
            ->call('supprimer', 'provinces', $province->id)
            ->call('supprimer', 'regions', $region->id);
        $this->assertNull($region->fresh());
        $this->assertSame(5, JournalAudit::where('action', 'organisation.suppression')->count());

        $ecran->call('ajouter', 'inconnu')->assertNotFound();
    }

    /* ============================================================== Paramètres */

    public function test_une_regle_parametrable_se_modifie_se_confirme_et_laisse_une_trace(): void
    {
        $ecran = Livewire::actingAs($this->admin)->test(Parametres::class)->assertSee('règle(s) provisoire(s)')->assertSee('Inscription en formation')
            ->assertSet('valeurs.restauration__sortie_annule_repas', false);

        // Valeurs hors liste ou mal formées : refusées.
        $ecran->set('valeurs.restauration__heure_limite_reservation', '25h')->call('enregistrer', 'restauration.heure_limite_reservation')->assertHasErrors('valeurs.restauration__heure_limite_reservation');
        $ecran->set('valeurs.classement__departage', 'tirage_au_sort')->call('enregistrer', 'classement.departage')->assertHasErrors('valeurs.classement__departage');
        $this->assertFalse(app(LectureParametres::class)->vrai('restauration.sortie_annule_repas'));
        $this->assertSame(0, JournalAudit::where('action', 'parametre.modification')->count());

        $ecran->set('valeurs.restauration__sortie_annule_repas', true)->call('enregistrer', 'restauration.sortie_annule_repas')->assertHasNoErrors();
        $this->assertTrue(app(LectureParametres::class)->vrai('restauration.sortie_annule_repas'));

        // Confirmer une règle provisoire : elle n'est plus provisoire ensuite.
        $ecran->set('valeurs.places__exclure_isolement_infirmerie', true)->set('confirmees.places__exclure_isolement_infirmerie', true)
            ->call('enregistrer', 'places.exclure_isolement_infirmerie')->assertHasNoErrors();
        $regle = Parametre::findOrFail('places.exclure_isolement_infirmerie');
        $this->assertSame([true, false, $this->admin->id], [$regle->valeur, $regle->a_valider, $regle->modifie_par]);

        $trace = JournalAudit::where('action', 'parametre.modification')->orderBy('id')->first();
        // MySQL range les clés d'un JSON à sa façon : l'ordre n'est pas comparé.
        $this->assertEquals(['cle' => 'restauration.sortie_annule_repas', 'avant' => false, 'apres' => true, 'a_valider' => true], $trace->details);

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
