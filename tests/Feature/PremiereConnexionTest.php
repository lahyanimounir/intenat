<?php

namespace Tests\Feature;

use App\Domain\Identity\MyWay\FournisseurIdentiteStagiaire;
use App\Domain\Identity\MyWay\ProfilMyWay;
use App\Enums\EtatCompte;
use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FournisseurParCode;
use Tests\Support\FournisseurParRedirection;
use Tests\TestCase;

/**
 * Première connexion d'un stagiaire, avec le fournisseur d'identité de test
 * (profils synthétiques) : vérification MyWay → « Inscrit Payé » → revue du
 * profil → mot de passe INTERNAT → compte activé.
 *
 * Aucun de ces tests ne contacte MyWay : ils prouvent le comportement
 * d'INTERNAT, pas celui d'une liaison réelle.
 */
class PremiereConnexionTest extends TestCase
{
    use RefreshDatabase;

    private const MYWAY = 'MyWay-Demo-2026';

    private const INTERNAT = 'Mon-Internat-2026';

    protected function setUp(): void
    {
        parent::setUp();

        $region = Region::factory()->create();
        Etablissement::factory()->create(['region_id' => $region->id, 'code_efp' => 'DL10', 'nom_efp' => 'ISTA Démo Littoral']);
        Etablissement::factory()->create(['region_id' => $region->id, 'code_efp' => 'DJ20', 'nom_efp' => 'ISTA Démo Les Jardins']);
    }

    private function verifier(string $email, string $motDePasse = self::MYWAY): TestResponse
    {
        return $this->postJson('/api/auth/stagiaire/verification', ['email' => $email, 'mot_de_passe_myway' => $motDePasse]);
    }

    /** @param  array<string, mixed>  $supplement */
    private function activer(?string $jeton, string $motDePasse = self::INTERNAT, array $supplement = []): TestResponse
    {
        return $this->postJson('/api/auth/stagiaire/activation', [
            'jeton_activation' => $jeton, 'mot_de_passe' => $motDePasse, 'mot_de_passe_confirmation' => $motDePasse,
        ] + $supplement);
    }

    private function profilVerifie(string $cef = '2026900000031', string $email = 'amal.idrissi@exemple.test'): ProfilMyWay
    {
        return new ProfilMyWay($cef, 'Idrissi', 'Amal', $email, 'ZZ900031', '0600900031', 'Settat', [
            ['nom_efp' => 'ISTA Démo Littoral', 'nom_filiere' => 'Développement digital', 'statut' => 'Inscrit Payé', 'annee' => '2026-2027'],
        ]);
    }

    public function test_l_ecran_de_premiere_connexion_connait_la_methode_du_fournisseur(): void
    {
        $this->getJson('/api/contexte')
            ->assertOk()
            ->assertJsonPath('myway.fournisseur', 'mock')
            ->assertJsonPath('myway.simule', true)
            ->assertJsonPath('myway.methode', 'identifiants')
            ->assertJsonPath('myway.disponible', true);
    }

    public function test_une_verification_reussie_presente_le_profil_importe_sans_creer_de_compte(): void
    {
        $reponse = $this->verifier('salma.bennani@myway.test')
            ->assertCreated()
            ->assertJsonPath('etape', 'verifiee')
            ->assertJsonPath('simulee', true)
            ->assertJsonPath('profil.cef', '2026900000011')
            ->assertJsonPath('profil.nom', 'Bennani')
            ->assertJsonPath('profil.email', 'salma.bennani@myway.test')
            // L'établissement vient de la candidature « Inscrit Payé », pas de la première de la liste.
            ->assertJsonPath('profil.etablissement.code_efp', 'DL10')
            ->assertJsonPath('profil.nom_filiere', 'Développement digital')
            ->assertJsonPath('profil.statut_candidature', 'Inscrit Payé')
            // Importé, déduit du référentiel ou manquant : chaque information dit d'où elle vient.
            ->assertJsonPath('origines.cef', 'importe')
            ->assertJsonPath('origines.code_efp', 'correspondance')
            ->assertJsonPath('modifiables', ['tel', 'ville']);

        // Une vérification n'est pas un compte : rien dans `stagiaire`, aucune session ouverte.
        $this->assertSame(0, Stagiaire::count());
        $this->assertGuest('stagiaire');

        $verification = VerificationIdentite::firstOrFail();
        $this->assertSame('verifiee', $verification->resultat->value);
        $this->assertTrue($verification->estUtilisable());

        // Le jeton n'est conservé que sous forme d'empreinte ; le profil est chiffré en base.
        $ligne = DB::table('verification_identite')->first();
        $this->assertSame(hash('sha256', $reponse->json('jeton_activation')), $ligne->jeton_hash);
        $this->assertStringNotContainsString($reponse->json('jeton_activation'), (string) json_encode($ligne));
        $this->assertStringNotContainsString('Bennani', (string) $ligne->profil);
        $this->assertStringNotContainsString(self::MYWAY, (string) json_encode($ligne));
    }

    public function test_un_stagiaire_eligible_cree_son_mot_de_passe_internat_et_son_compte_est_active(): void
    {
        $jeton = $this->verifier('salma.bennani@myway.test')->json('jeton_activation');

        $this->activer($jeton)
            ->assertCreated()
            ->assertJsonPath('type', 'stagiaire')
            ->assertJsonPath('stagiaire.cef', '2026900000011')
            ->assertJsonPath('stagiaire.etat_compte.valeur', 'actif')
            ->assertJsonPath('stagiaire.source_identite', 'mock');

        $stagiaire = Stagiaire::where('email', 'salma.bennani@myway.test')->firstOrFail();
        $this->assertAuthenticatedAs($stagiaire, 'stagiaire');

        // Les dix informations reprises ; le Code EFP vient de la correspondance tenue par l'administrateur.
        $this->assertSame('DL10', $stagiaire->etablissement->code_efp);
        $this->assertSame(
            ['Bennani', 'Salma', 'ZZ900011', '0600900011', 'Settat', 'Développement digital', 'Inscrit Payé'],
            [$stagiaire->nom, $stagiaire->prenom, $stagiaire->cni, $stagiaire->tel, $stagiaire->ville, $stagiaire->nom_filiere, $stagiaire->statut_myway],
        );
        $this->assertSame(EtatCompte::Actif, $stagiaire->etat_compte);
        $this->assertNotNull($stagiaire->compte_active_le);
        $this->assertSame('correspondance', $stagiaire->origine_champs['code_efp']);

        // Mot de passe INTERNAT haché en Argon2id ; rien de MyWay n'est conservé.
        $this->assertStringStartsWith('$argon2id$', $stagiaire->mot_de_passe_hash);
        $this->assertStringNotContainsString(self::MYWAY, (string) json_encode(DB::table('stagiaire')->get()));
        $this->assertStringNotContainsString(self::MYWAY, (string) json_encode(JournalAudit::all()->toArray()));
        $this->assertStringNotContainsString(self::INTERNAT, (string) json_encode(JournalAudit::all()->toArray()));

        // L'autorisation est consommée et le profil vérifié n'est plus conservé à part.
        $verification = VerificationIdentite::firstOrFail();
        $this->assertNotNull($verification->consommee_le);
        $this->assertSame($stagiaire->id, $verification->stagiaire_id);
        $this->assertNull($verification->profil);
        $this->assertDatabaseHas('journal_audit', ['action' => 'stagiaire.activation', 'stagiaire_id' => $stagiaire->id]);
    }

    public function test_l_autorisation_d_activation_ne_sert_qu_une_fois_et_expire(): void
    {
        $jeton = $this->verifier('youssef.tazi@myway.test')->json('jeton_activation');
        $this->activer($jeton)->assertCreated();
        $this->postJson('/api/auth/deconnexion');

        // Rejeu : le même jeton ne permet ni de recréer le compte ni de changer son mot de passe.
        $this->activer($jeton, 'Autre-Internat-2026')->assertStatus(422)->assertJsonPath('code', 'jeton_expire');
        $this->postJson('/api/auth/stagiaire/connexion', ['email' => 'youssef.tazi@myway.test', 'mot_de_passe' => 'Autre-Internat-2026'])->assertStatus(422);

        // Expiration : passé le délai, il faut refaire la vérification.
        $autre = $this->verifier('nour.lahlou@myway.test')->json('jeton_activation');
        $this->travel((int) config('internat.myway.activation_minutes') + 1)->minutes();
        $this->activer($autre)->assertStatus(422)->assertJsonPath('code', 'jeton_expire');
        $this->assertSame(1, Stagiaire::count());

        // Un jeton inventé n'ouvre rien, même accompagné d'un CEF, d'une adresse et d'une CNI exacts.
        $this->activer(str_repeat('a', 64), self::INTERNAT, ['cef' => '2026900000013', 'email' => 'nour.lahlou@myway.test', 'cni' => 'ZZ900013'])
            ->assertStatus(422)->assertJsonPath('code', 'jeton_expire');
        $this->assertSame(1, Stagiaire::count());
    }

    public function test_une_nouvelle_verification_ferme_l_autorisation_precedente(): void
    {
        $premier = $this->verifier('salma.bennani@myway.test')->json('jeton_activation');
        $second = $this->verifier('salma.bennani@myway.test')->json('jeton_activation');

        $this->activer($premier)->assertStatus(422)->assertJsonPath('code', 'jeton_expire');
        $this->activer($second)->assertCreated();
        $this->assertSame(1, Stagiaire::count());
    }

    public function test_le_stagiaire_ne_peut_ni_s_attribuer_une_identite_ni_se_declarer_eligible(): void
    {
        $jeton = $this->verifier('nour.lahlou@myway.test')->json('jeton_activation');

        // Seules les coordonnées sont corrigeables : tout le reste vient de la vérification conservée par le serveur.
        $this->activer($jeton, self::INTERNAT, [
            'tel' => '0611223344', 'ville' => 'Rabat',
            'cef' => '9999999999999', 'nom' => 'Usurpateur', 'email' => 'autre@exemple.test', 'cni' => 'AA000000',
            'statut_myway' => 'Inscrit Payé', 'etablissement_id' => Etablissement::where('code_efp', 'DL10')->value('id'),
        ])->assertCreated();

        $stagiaire = Stagiaire::firstOrFail();
        $this->assertSame(['2026900000013', 'Lahlou', 'nour.lahlou@myway.test', null], [$stagiaire->cef, $stagiaire->nom, $stagiaire->email, $stagiaire->cni]);
        $this->assertSame('DJ20', $stagiaire->etablissement->code_efp);
        $this->assertSame(['0611223344', 'Rabat'], [$stagiaire->tel, $stagiaire->ville]);

        // Ce qui a été corrigé est marqué « déclaré » ; ce que MyWay n'a pas communiqué reste « manquant ».
        $this->assertSame(['declare', 'declare', 'manquant', 'importe'], [
            $stagiaire->origine_champs['tel'], $stagiaire->origine_champs['ville'], $stagiaire->origine_champs['cni'], $stagiaire->origine_champs['cef'],
        ]);
    }

    public function test_le_mot_de_passe_internat_est_soumis_a_une_politique(): void
    {
        $jeton = $this->verifier('salma.bennani@myway.test')->json('jeton_activation');

        $this->activer($jeton, 'court')->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');
        $this->activer($jeton, 'sansmajusculenichiffre')->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');
        $this->postJson('/api/auth/stagiaire/activation', [
            'jeton_activation' => $jeton, 'mot_de_passe' => self::INTERNAT, 'mot_de_passe_confirmation' => 'Different-2026-x',
        ])->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');
        // Ni l'adresse ni le CEF du compte dans le mot de passe.
        $this->activer($jeton, 'Aa-2026900000011')->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');

        // Aucun de ces refus n'a consommé l'autorisation.
        $this->assertSame(0, Stagiaire::count());
        $this->assertTrue(VerificationIdentite::firstOrFail()->estUtilisable());
    }

    public function test_sans_candidature_inscrit_paye_l_acces_est_refuse_et_rien_n_est_enregistre(): void
    {
        $this->verifier('candidat.non.paye@myway.test')
            ->assertStatus(403)
            ->assertJsonPath('code', 'non_eligible')
            ->assertJsonMissingPath('jeton_activation');

        // Ni compte, ni vérification en attente, ni donnée personnelle dans le journal.
        $this->assertSame(0, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::count());
        $this->assertGuest('stagiaire');

        $trace = JournalAudit::where('action', 'stagiaire.verification_refusee')->firstOrFail();
        $this->assertEquals(['motif' => 'non_eligible', 'fournisseur' => 'mock'], $trace->details);
        $this->assertStringNotContainsString('Amrani', (string) json_encode(JournalAudit::all()->toArray()));
    }

    public function test_un_cef_absent_ou_mal_forme_empeche_l_activation_automatique(): void
    {
        $this->verifier('cef.absent@myway.test')
            ->assertStatus(422)
            ->assertJsonPath('code', 'informations_incompletes')
            ->assertJsonPath('contexte.champs_manquants', ['cef'])
            ->assertJsonMissingPath('jeton_activation');

        $this->verifier('cef.invalide@myway.test')
            ->assertStatus(422)
            ->assertJsonPath('code', 'informations_incompletes');

        $this->assertSame(0, Stagiaire::count());

        // Gardé pour examen par l'administration : ce qui manque, sans profil ni autorisation d'activation.
        $aExaminer = VerificationIdentite::where('email', 'cef.absent@myway.test')->firstOrFail();
        $this->assertSame('informations_incompletes', $aExaminer->resultat->value);
        $this->assertSame(['cef'], $aExaminer->champs_manquants);
        $this->assertSame([null, null, null], [$aExaminer->cef, $aExaminer->jeton_hash, $aExaminer->profil]);
        $this->assertSame(2, VerificationIdentite::aExaminer()->count());
    }

    public function test_un_etablissement_non_reference_n_est_jamais_accepte_en_silence(): void
    {
        $this->verifier('efp.inconnu@myway.test')->assertStatus(422)->assertJsonPath('code', 'efp_inconnu');
        // Une seconde tentative met à jour la ligne ouverte au lieu d'en créer une autre.
        $this->verifier('efp.inconnu@myway.test')->assertStatus(422);

        $this->assertSame(0, Stagiaire::count());
        $aExaminer = VerificationIdentite::sole();
        $this->assertSame(['efp_inconnu', 'ISTA Non Référencé', null], [$aExaminer->resultat->value, $aExaminer->nom_efp, $aExaminer->etablissement_id]);

        // Une fois la correspondance Nom EFP → Code EFP saisie par l'administrateur, la vérification aboutit.
        Etablissement::factory()->create(['code_efp' => 'NR30', 'nom_efp' => 'ISTA non référencé']);
        $this->verifier('efp.inconnu@myway.test')->assertCreated()->assertJsonPath('profil.etablissement.code_efp', 'NR30');
    }

    public function test_plusieurs_inscriptions_eligibles_ne_sont_pas_departagees_arbitrairement(): void
    {
        // Deux « Inscrit Payé » dans deux établissements, sans année : INTERNAT ne choisit pas.
        $this->verifier('deux.inscriptions@myway.test')->assertStatus(409)->assertJsonPath('code', 'candidature_ambigue');
        $this->assertSame(0, Stagiaire::count());
        $this->assertSame('candidature_ambigue', VerificationIdentite::sole()->resultat->value);

        // Avec l'année de formation, une inscription passée ne l'emporte pas sur celle de l'année en cours.
        $this->verifier('ancienne.inscription@myway.test')
            ->assertCreated()
            ->assertJsonPath('profil.etablissement.code_efp', 'DL10')
            ->assertJsonPath('profil.annee_formation', '2026-2027')
            ->assertJsonPath('origines.tel', 'manquant');
    }

    public function test_un_compte_existant_n_est_ni_duplique_ni_ecrase(): void
    {
        $jeton = $this->verifier('salma.bennani@myway.test')->json('jeton_activation');
        $this->activer($jeton)->assertCreated();
        $this->postJson('/api/auth/deconnexion');
        $empreinte = Stagiaire::firstOrFail()->mot_de_passe_hash;

        // Sans preuve d'identité, la première connexion ne dit pas qu'un compte existe.
        $this->verifier('salma.bennani@myway.test', 'mauvais')->assertStatus(422)->assertJsonPath('code', 'identifiants_invalides');

        // Le titulaire, lui, est renvoyé vers la connexion habituelle : aucune nouvelle autorisation n'est délivrée.
        $this->verifier('salma.bennani@myway.test')
            ->assertStatus(409)
            ->assertJsonPath('code', 'compte_existant')
            ->assertJsonMissingPath('jeton_activation');

        $this->assertSame(1, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::enCours()->count());
        $this->assertSame($empreinte, Stagiaire::firstOrFail()->mot_de_passe_hash);
        $this->postJson('/api/auth/stagiaire/connexion', ['email' => 'salma.bennani@myway.test', 'mot_de_passe' => self::INTERNAT])->assertOk();
    }

    public function test_un_cef_ou_une_adresse_deja_rattache_a_un_autre_compte_est_un_conflit(): void
    {
        Stagiaire::factory()->create(['cef' => '2026900000011', 'email' => 'autre.personne@exemple.test']);
        Stagiaire::factory()->create(['cef' => '2026900000099', 'email' => 'youssef.tazi@myway.test']);

        $this->verifier('salma.bennani@myway.test')->assertStatus(409)->assertJsonPath('code', 'conflit_identite');
        $this->verifier('youssef.tazi@myway.test')->assertStatus(409)->assertJsonPath('code', 'conflit_identite');

        $this->assertSame(2, Stagiaire::count());
        $this->assertSame(2, VerificationIdentite::where('resultat', 'conflit_identite')->count());
        $this->assertSame(0, VerificationIdentite::enCours()->count());
    }

    public function test_une_ligne_heritee_jamais_activee_est_reprise_sans_doublon(): void
    {
        // Ancien parcours : profil enregistré avant le choix du mot de passe, compte jamais activé.
        $heritee = Stagiaire::factory()->create([
            'cef' => '2026900000011', 'email' => 'salma.bennani@myway.test', 'nom' => 'Ancien nom',
            'mot_de_passe_hash' => null, 'compte_active_le' => null, 'etat_compte' => EtatCompte::EnAttente,
        ]);

        // Elle ne permet pas de se connecter…
        $this->postJson('/api/auth/stagiaire/connexion', ['email' => 'salma.bennani@myway.test', 'mot_de_passe' => self::INTERNAT])->assertStatus(422);

        // … et l'activation la reprend : même identifiant, donc dossiers et historique conservés.
        $this->activer($this->verifier('salma.bennani@myway.test')->assertCreated()->json('jeton_activation'))->assertCreated();

        $this->assertSame(1, Stagiaire::count());
        $this->assertSame(['Bennani', EtatCompte::Actif], [$heritee->fresh()->nom, $heritee->fresh()->etat_compte]);
    }

    public function test_les_pannes_et_reponses_incoherentes_du_fournisseur_sont_gerees(): void
    {
        $this->verifier('indisponible@myway.test', 'x')->assertStatus(503)->assertJsonPath('code', 'fournisseur_indisponible');
        $this->verifier('reponse-invalide@myway.test', 'x')->assertStatus(502)->assertJsonPath('code', 'reponse_inattendue');

        // Une panne ne crée rien, et ne bascule jamais vers une autre source d'identité.
        $this->assertSame(0, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::count());
    }

    public function test_sans_mecanisme_approuve_la_premiere_connexion_est_fermee(): void
    {
        config(['internat.myway.fournisseur' => 'approuve']);

        $this->getJson('/api/contexte')
            ->assertJsonPath('myway.fournisseur', 'approuve')
            ->assertJsonPath('myway.simule', false)
            ->assertJsonPath('myway.methode', 'indisponible')
            ->assertJsonPath('myway.disponible', false);

        $this->verifier('salma.bennani@myway.test')->assertStatus(503)->assertJsonPath('code', 'fournisseur_indisponible');
        $this->postJson('/api/auth/stagiaire/verification/retour', ['etat' => 'x', 'parametres' => ['code' => 'y']])->assertStatus(422);

        $this->assertSame(0, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::count());
    }

    public function test_le_simulateur_myway_ne_s_active_jamais_en_production(): void
    {
        // Même configuré par erreur sur « mock », un serveur de production n'accepte aucun profil fictif.
        config(['internat.myway.fournisseur' => 'mock', 'internat.mode_demo' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->getJson('/api/contexte')
            ->assertJsonPath('myway.simule', false)
            ->assertJsonPath('myway.disponible', false)
            ->assertJsonPath('mode_demo', false)
            ->assertJsonPath('comptes_demo', null);

        // Hors environnement de test, la protection CSRF s'applique : elle n'est pas l'objet de ce cas.
        $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);

        $this->verifier('salma.bennani@myway.test')->assertStatus(503);
        $this->assertSame(0, Stagiaire::count());
        $this->assertSame(0, VerificationIdentite::count());
    }

    public function test_un_fournisseur_par_redirection_s_insere_dans_le_meme_parcours(): void
    {
        $this->app->bind(FournisseurIdentiteStagiaire::class, fn () => new FournisseurParRedirection($this->profilVerifie()));

        $this->getJson('/api/contexte')->assertJsonPath('myway.methode', 'redirection');

        // Aucun mot de passe du fournisseur ne transite par INTERNAT : le stagiaire est envoyé s'authentifier chez lui.
        $depart = $this->postJson('/api/auth/stagiaire/verification')->assertOk()->assertJsonPath('etape', 'redirection');
        parse_str((string) parse_url($depart->json('url'), PHP_URL_QUERY), $requete);
        $this->assertSame('http://localhost:5173/premiere-connexion/retour', $requete['redirect_uri']);

        // Un retour dont l'état ne correspond pas à ce navigateur est refusé, et l'état ne se rejoue pas.
        $this->postJson('/api/auth/stagiaire/verification/retour', ['etat' => 'etat-forge', 'parametres' => ['code' => FournisseurParRedirection::PREUVE_VALIDE]])
            ->assertStatus(422)->assertJsonPath('code', 'identifiants_invalides');
        $this->postJson('/api/auth/stagiaire/verification/retour', ['etat' => $requete['state'], 'parametres' => ['code' => FournisseurParRedirection::PREUVE_VALIDE]])
            ->assertStatus(422);

        $depart = $this->postJson('/api/auth/stagiaire/verification')->assertOk();
        parse_str((string) parse_url($depart->json('url'), PHP_URL_QUERY), $requete);

        $retour = $this->postJson('/api/auth/stagiaire/verification/retour', [
            'etat' => $requete['state'], 'parametres' => ['code' => FournisseurParRedirection::PREUVE_VALIDE],
        ])->assertCreated()->assertJsonPath('etape', 'verifiee')->assertJsonPath('profil.cef', '2026900000031');

        $this->activer($retour->json('jeton_activation'))->assertCreated();
        $this->assertSame(['test-sso', '2026-2027'], [Stagiaire::sole()->source_identite, Stagiaire::sole()->annee_formation]);
    }

    public function test_un_fournisseur_par_code_s_insere_dans_le_meme_parcours(): void
    {
        $fournisseur = new FournisseurParCode($this->profilVerifie());
        $this->app->instance(FournisseurIdentiteStagiaire::class, $fournisseur);

        // Connaître le CEF et l'adresse ne suffit pas : la réponse est la même, et aucun code ne part pour une identité inexacte.
        $inexacte = $this->postJson('/api/auth/stagiaire/verification', ['cef' => '2026900000031', 'email' => 'quelqu.un@exemple.test'])
            ->assertStatus(202)->assertJsonPath('etape', 'code');
        $this->postJson('/api/auth/stagiaire/verification/retour', ['reference' => $inexacte->json('reference'), 'code' => FournisseurParCode::CODE])
            ->assertStatus(422)->assertJsonPath('code', 'identifiants_invalides');

        $demande = $this->postJson('/api/auth/stagiaire/verification', ['cef' => '2026900000031', 'email' => 'amal.idrissi@exemple.test'])
            ->assertStatus(202)->assertJsonPath('canal', 'adresse e-mail enregistrée');
        $this->assertSame($inexacte->json(), $demande->json());

        $this->postJson('/api/auth/stagiaire/verification/retour', ['reference' => $demande->json('reference'), 'code' => '000000'])->assertStatus(422);

        $retour = $this->postJson('/api/auth/stagiaire/verification/retour', ['reference' => $demande->json('reference'), 'code' => FournisseurParCode::CODE])
            ->assertCreated()->assertJsonPath('profil.email', 'amal.idrissi@exemple.test');

        $this->activer($retour->json('jeton_activation'))->assertCreated();
        $this->assertSame('test-registre', Stagiaire::sole()->source_identite);
    }

    public function test_la_base_refuse_un_compte_actif_sans_date_d_activation_ou_sans_mot_de_passe(): void
    {
        $etablissement = Etablissement::first()->id;
        $ligne = fn (array $ecarts) => [
            'etablissement_id' => $etablissement, 'cef' => '2026900000077', 'nom' => 'N', 'prenom' => 'P', 'email' => 'x@exemple.test',
            'nom_filiere' => 'F', 'date_import_myway' => now(), 'created_at' => now(), 'updated_at' => now(),
        ] + $ecarts;

        foreach ([
            ['etat_compte' => 'actif', 'compte_active_le' => null, 'mot_de_passe_hash' => 'x'],
            ['etat_compte' => 'actif', 'compte_active_le' => now(), 'mot_de_passe_hash' => null],
            ['etat_compte' => 'en_attente', 'compte_active_le' => now(), 'mot_de_passe_hash' => 'x'],
        ] as $incoherent) {
            try {
                DB::table('stagiaire')->insert($ligne($incoherent));
                $this->fail('La contrainte aurait dû refuser cette ligne.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('stagiaire_etat_compte_coherent', $e->getMessage());
            }
        }

        $this->assertSame(0, Stagiaire::count());
    }

    public function test_les_verifications_expirees_perdent_leur_profil_puis_sont_supprimees(): void
    {
        $this->verifier('salma.bennani@myway.test')->assertCreated();
        $this->verifier('efp.inconnu@myway.test')->assertStatus(422);

        $this->travel((int) config('internat.myway.activation_minutes') + 5)->minutes();
        $this->artisan('internat:purger-verifications')->assertExitCode(0);

        // Expirée sans activation : le profil vérifié est effacé, la ligne reste le temps de la conservation.
        $this->assertNull(DB::table('verification_identite')->where('resultat', 'verifiee')->value('profil'));

        $this->travel((int) config('internat.myway.conservation_verifications_jours') + 1)->days();
        $this->artisan('internat:purger-verifications')->assertExitCode(0);

        // La vérification close est supprimée ; celle qui attend un examen est gardée.
        $this->assertSame(['efp_inconnu'], VerificationIdentite::pluck('resultat')->map->value->all());
    }
}
