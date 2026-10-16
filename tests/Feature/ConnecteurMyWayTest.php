<?php

namespace Tests\Feature;

use App\Models\Etablissement;
use App\Models\JournalAudit;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Connexion réelle à MyWay par le service `myway-connector`.
 *
 * Le connecteur est remplacé par ses réponses de contrat
 * (`myway-connector/contrat/*.json`, que ses propres tests d'intégration
 * produisent à l'identique) : ce qui est vérifié ici, c'est qu'INTERNAT
 * n'enregistre une identité que si elle a été réellement lue sur MyWay, pour
 * un compte autorisé, et qu'il n'y substitue jamais une simulation.
 */
class ConnecteurMyWayTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://connecteur.test';

    private const JETON = 'jeton-de-test-0123456789abcdef0123456789abcdef';

    private const COMPTE = 'stagiaire.complet@faux-myway.test';

    private const COMPTE_OBSERVE = 'stagiaire.observe@faux-myway.test';

    private const MOT_DE_PASSE = 'Mot-De-Passe-MyWay-Du-Titulaire-1';

    protected function setUp(): void
    {
        parent::setUp();

        $region = Region::factory()->create();
        Etablissement::factory()->create(['region_id' => $region->id, 'code_efp' => 'DL10', 'nom_efp' => 'ISTA Démo Littoral']);
        Etablissement::factory()->create(['region_id' => $region->id, 'code_efp' => 'DJ20', 'nom_efp' => 'ISTA Démo Les Jardins']);

        config([
            'internat.myway.fournisseur' => 'connecteur',
            'internat.myway.connecteur.url' => self::URL,
            'internat.myway.connecteur.jeton' => self::JETON,
            'internat.myway.connecteur.comptes_autorises' => ' '.strtoupper(self::COMPTE).' , '.self::COMPTE_OBSERVE.', salma.bennani@myway.test',
        ]);

        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function contrat(string $nom): array
    {
        return json_decode((string) file_get_contents(base_path("../myway-connector/contrat/{$nom}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>|null  $corps */
    private function connecteurRepond(?array $corps = null, int $statut = 200): void
    {
        Http::fake([self::URL.'/*' => Http::response($corps ?? $this->contrat('verification-complete'), $statut)]);
    }

    private function premiereConnexion(string $email = self::COMPTE, string $motDePasse = self::MOT_DE_PASSE)
    {
        return $this->postJson('/api/auth/stagiaire/verification', ['email' => $email, 'mot_de_passe_myway' => $motDePasse]);
    }

    public function test_l_ecran_de_connexion_est_informe_d_une_connexion_reelle_et_non_simulee(): void
    {
        $this->getJson('/api/contexte')
            ->assertOk()
            ->assertJsonPath('myway.fournisseur', 'connecteur')
            ->assertJsonPath('myway.simule', false)
            ->assertJsonPath('myway.methode', 'identifiants');
    }

    public function test_un_compte_autorise_verifie_sur_myway_importe_son_profil_puis_active_son_compte(): void
    {
        $this->connecteurRepond();

        $reponse = $this->premiereConnexion()
            ->assertCreated()
            ->assertJsonPath('etape', 'verifiee')
            ->assertJsonPath('simulee', false)
            ->assertJsonPath('profil.cef', '2026900000021')
            ->assertJsonPath('profil.statut_candidature', 'inscrit_paye')
            ->assertJsonPath('origines.ville', 'manquant');

        // Un seul appel, authentifié par le jeton partagé, vers le point de vérification.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $requete) => $requete->url() === self::URL.'/v1/verifications'
            && $requete->method() === 'POST'
            && $requete->hasHeader('Authorization', 'Bearer '.self::JETON)
            && $requete->data() === ['email' => self::COMPTE, 'mot_de_passe' => self::MOT_DE_PASSE]);

        // La vérification n'est pas un compte : aucune ligne `stagiaire` tant que le mot de passe INTERNAT n'est pas choisi.
        $this->assertSame(0, Stagiaire::count());
        $verification = VerificationIdentite::where('email', self::COMPTE)->firstOrFail();
        $this->assertSame(['connecteur', false, 'inscrit_paye'], [$verification->fournisseur, $verification->simulee, $verification->statut_candidature]);

        // Le mot de passe MyWay n'est ni conservé, ni haché, ni tracé.
        $trace = JournalAudit::where('action', 'stagiaire.verification_identite')->firstOrFail();
        $this->assertSame('connecteur', $trace->details['fournisseur']);
        $this->assertFalse($trace->details['simule']);
        $this->assertSame('backend:/stagiaire/profil → data.cef', $trace->details['provenance']['cef']);
        $this->assertStringNotContainsString(self::MOT_DE_PASSE, (string) json_encode(JournalAudit::all()->toArray()));
        $this->assertStringNotContainsString(self::MOT_DE_PASSE, (string) json_encode(DB::table('verification_identite')->get()));

        // La suite est inchangée : mot de passe propre à INTERNAT, que celui de MyWay n'ouvre pas.
        $this->postJson('/api/auth/stagiaire/activation', [
            'jeton_activation' => $reponse->json('jeton_activation'),
            'mot_de_passe' => 'Mon-Internat-2026', 'mot_de_passe_confirmation' => 'Mon-Internat-2026',
        ])->assertCreated()->assertJsonPath('type', 'stagiaire');

        $stagiaire = Stagiaire::where('email', self::COMPTE)->firstOrFail();

        // CEF, identité, établissement, filière et statut : tels que lus, l'établissement venant de la candidature « Inscrit Payé ».
        $this->assertSame('DL10', $stagiaire->etablissement->code_efp);
        $this->assertSame(
            ['2026900000021', 'FICTIF', 'SALMA', 'ZZ900021', '0600900021', null, 'Développement digital', 'connecteur', 'inscrit_paye'],
            [$stagiaire->cef, $stagiaire->nom, $stagiaire->prenom, $stagiaire->cni, $stagiaire->tel, $stagiaire->ville, $stagiaire->nom_filiere, $stagiaire->source_identite, $stagiaire->statut_myway],
        );
        $this->assertNotNull($stagiaire->date_import_myway);

        $this->postJson('/api/auth/deconnexion')->assertOk();
        $this->postJson('/api/auth/stagiaire/connexion', ['email' => self::COMPTE, 'mot_de_passe' => self::MOT_DE_PASSE])->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_un_compte_hors_de_la_liste_autorisee_est_refuse_sans_aucun_appel(): void
    {
        $this->connecteurRepond();

        $this->premiereConnexion('quelqu.un.d.autre@faux-myway.test')
            ->assertStatus(403)
            ->assertJsonPath('code', 'compte_non_autorise');

        Http::assertNothingSent();
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_sans_cef_ni_statut_reellement_lus_aucun_compte_n_est_cree(): void
    {
        // Forme des réponses observées sur MyWay à ce jour : identité, établissement et filière, mais ni CEF ni statut.
        $this->connecteurRepond($this->contrat('verification-incomplete'));

        $reponse = $this->premiereConnexion(self::COMPTE_OBSERVE)
            ->assertStatus(502)
            ->assertJsonPath('code', 'donnees_non_verifiees')
            ->assertJsonPath('contexte.champs_manquants', ['cef', 'statut_candidature']);

        $this->assertStringContainsString('CEF, statut de candidature', $reponse->json('message'));
        $this->assertSame(0, Stagiaire::count());
        $this->assertSame(0, JournalAudit::where('action', 'stagiaire.verification_identite')->count());

        // Rien n'est comblé : la tentative est gardée pour examen, avec ce qui manque et sans profil ni autorisation.
        $aExaminer = VerificationIdentite::firstOrFail();
        $this->assertSame('informations_incompletes', $aExaminer->resultat->value);
        $this->assertSame(['cef', 'statut_candidature'], $aExaminer->champs_manquants);
        $this->assertSame([self::COMPTE_OBSERVE, null, null], [$aExaminer->email, $aExaminer->jeton_hash, $aExaminer->profil]);
    }

    public function test_l_indicateur_complet_du_connecteur_n_est_pas_cru_sur_parole(): void
    {
        $corps = $this->contrat('verification-complete');
        $corps['profil']['cef'] = null;

        $this->connecteurRepond($corps);

        $this->premiereConnexion()->assertStatus(502)->assertJsonPath('contexte.champs_manquants', ['cef']);
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_un_resultat_qui_ne_vient_pas_du_site_officiel_est_refuse(): void
    {
        // Ce que renvoie un connecteur lancé contre le banc d'essai : mêmes données, cible non officielle.
        $corps = $this->contrat('verification-complete');
        $corps['cible'] = ['site' => 'http://127.0.0.1:4010', 'backend' => 'http://127.0.0.1:4011', 'officielle' => false];

        $this->connecteurRepond($corps);

        $this->premiereConnexion()->assertStatus(502)->assertJsonPath('code', 'reponse_inattendue');
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_le_profil_d_un_autre_compte_est_refuse(): void
    {
        $corps = $this->contrat('verification-complete');
        $corps['profil']['email'] = 'quelqu.un.d.autre@faux-myway.test';

        $this->connecteurRepond($corps);

        $this->premiereConnexion()->assertStatus(502)->assertJsonPath('code', 'reponse_inattendue');
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_un_statut_reel_autre_qu_inscrit_paye_refuse_l_acces(): void
    {
        $corps = $this->contrat('verification-complete');
        $corps['profil']['candidatures'] = [['nom_efp' => 'ISTA Démo Littoral', 'nom_filiere' => 'Génie civil', 'statut' => 'preinscrit']];

        $this->connecteurRepond($corps);

        $this->premiereConnexion()->assertStatus(403)->assertJsonPath('code', 'non_eligible');
        $this->assertSame(0, Stagiaire::count());
    }

    /** @return array<string, array{int, string, array<string, mixed>, int, string}> */
    public static function erreursDuConnecteur(): array
    {
        return [
            'identifiants refusés par MyWay' => [422, 'identifiants_invalides', ['etape' => 'connexion'], 422, 'identifiants_invalides'],
            'CAPTCHA demandé' => [409, 'verification_humaine_requise', ['etape' => 'ouverture', 'obstacle' => 'captcha'], 409, 'verification_requise'],
            'code de vérification demandé' => [409, 'verification_humaine_requise', ['etape' => 'connexion', 'obstacle' => 'mfa'], 409, 'verification_requise'],
            'accès bloqué par le pare-feu' => [503, 'acces_bloque', ['etape' => 'ouverture', 'obstacle' => 'pare_feu'], 503, 'fournisseur_indisponible'],
            'MyWay en panne' => [503, 'myway_indisponible', ['etape' => 'ouverture'], 503, 'fournisseur_indisponible'],
            'délai dépassé' => [504, 'delai_depasse', ['etape' => 'profil'], 503, 'fournisseur_indisponible'],
            'navigateur absent' => [503, 'navigateur_indisponible', ['etape' => 'lancement'], 503, 'fournisseur_indisponible'],
            'pages non reconnues' => [502, 'reponse_inattendue', ['etape' => 'ouverture', 'motif' => 'formulaire_introuvable'], 502, 'reponse_inattendue'],
            'vérification déjà en cours' => [429, 'occupe', ['reessayer_dans_s' => 15], 429, 'trop_de_tentatives'],
            'tentatives trop rapprochées' => [429, 'trop_de_tentatives', ['reessayer_dans_s' => 12], 429, 'trop_de_tentatives'],
            'jeton refusé par le connecteur' => [401, 'non_authentifie', [], 503, 'fournisseur_indisponible'],
            'compte refusé par le connecteur' => [403, 'compte_non_autorise', [], 403, 'compte_non_autorise'],
            'erreur interne du connecteur' => [500, 'erreur_interne', ['etape' => 'extraction'], 503, 'fournisseur_indisponible'],
        ];
    }

    /** @param  array<string, mixed>  $details */
    #[DataProvider('erreursDuConnecteur')]
    public function test_chaque_erreur_du_connecteur_est_traduite_sans_creer_de_compte(int $statut, string $code, array $details, int $statutAttendu, string $codeAttendu): void
    {
        Log::spy();
        $this->connecteurRepond(['code' => $code, 'message' => 'message du connecteur', 'details' => $details], $statut);

        $this->premiereConnexion()->assertStatus($statutAttendu)->assertJsonPath('code', $codeAttendu);

        $this->assertSame(0, Stagiaire::count());

        // L'échec est journalisé pour le diagnostic, sans adresse ni mot de passe.
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $contexte) use ($code) {
            $trace = $message.json_encode($contexte);

            return $contexte['code'] === $code && ! str_contains($trace, self::MOT_DE_PASSE) && ! str_contains($trace, self::COMPTE);
        })->once();
    }

    public function test_une_verification_humaine_est_signalee_avec_son_type(): void
    {
        $this->connecteurRepond(['code' => 'verification_humaine_requise', 'message' => '…', 'details' => ['obstacle' => 'captcha']], 409);

        $reponse = $this->premiereConnexion()->assertStatus(409)->assertJsonPath('contexte.obstacle', 'captcha');

        $this->assertStringContainsString('ne la contourne pas', $reponse->json('message'));
    }

    public function test_un_connecteur_injoignable_ne_declenche_aucune_simulation(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        // Même pour une adresse et un mot de passe connus du simulateur : aucun profil fictif ne prend le relais.
        $this->premiereConnexion('salma.bennani@myway.test', 'MyWay-Demo-2026')
            ->assertStatus(503)
            ->assertJsonPath('code', 'fournisseur_indisponible');

        $this->assertSame(0, Stagiaire::count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function configurationsIncompletes(): array
    {
        return [
            'jeton absent' => [['internat.myway.connecteur.jeton' => null]],
            'jeton trop court' => [['internat.myway.connecteur.jeton' => 'court']],
            'aucun compte autorisé' => [['internat.myway.connecteur.comptes_autorises' => ' , ']],
            'adresse du connecteur absente' => [['internat.myway.connecteur.url' => '']],
        ];
    }

    /** @param  array<string, mixed>  $configuration */
    #[DataProvider('configurationsIncompletes')]
    public function test_un_connecteur_mal_configure_refuse_tout_sans_aucun_appel(array $configuration): void
    {
        config($configuration);
        $this->connecteurRepond();

        $this->premiereConnexion()->assertStatus(503)->assertJsonPath('code', 'fournisseur_indisponible');

        Http::assertNothingSent();
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_le_connecteur_n_est_jamais_actif_en_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->connecteurRepond();

        // En production, c'est le fournisseur officiel — non configuré — qui répond.
        $this->getJson('/api/contexte')->assertJsonPath('myway.fournisseur', 'approuve');

        $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
        $this->premiereConnexion()->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_le_connecteur_n_est_jamais_actif_en_mode_demonstration(): void
    {
        // Séparation stricte : une identité réelle n'entre pas dans une base de données synthétiques.
        config(['internat.mode_demo' => true]);
        $this->connecteurRepond();

        $this->getJson('/api/contexte')
            ->assertJsonPath('mode_demo', true)
            ->assertJsonPath('myway.fournisseur', 'approuve')
            ->assertJsonPath('myway.simule', false)
            ->assertJsonPath('comptes_demo.myway', []);

        $this->premiereConnexion()->assertStatus(503)->assertJsonPath('code', 'fournisseur_indisponible');

        Http::assertNothingSent();
        $this->assertSame(0, Stagiaire::count());
    }

    public function test_le_diagnostic_dit_quel_fournisseur_est_actif_et_pourquoi(): void
    {
        Http::fake([self::URL.'/sante' => Http::response([
            'statut' => 'ok', 'service' => 'myway-connector', 'version' => '0.1.0', 'comptes_autorises' => 2,
            'cible' => ['site' => 'https://www.myway.ac.ma', 'backend' => 'https://backend.myway.ac.ma', 'officielle' => true],
        ])]);

        $this->artisan('internat:myway-diagnostic')
            ->expectsOutputToContain('Fournisseur actif                       : connecteur')
            ->expectsOutputToContain('correctement réglée')
            // Un connecteur qui répond ne prouve rien sur MyWay : la commande le dit, et n'annonce aucun succès.
            ->expectsOutputToContain('MyWay n\'a pas été sollicité')
            ->doesntExpectOutputToContain('opérationnelle')
            ->assertExitCode(0);
    }

    public function test_le_diagnostic_explique_un_reglage_incoherent(): void
    {
        config(['internat.mode_demo' => true, 'internat.myway.connecteur.jeton' => 'court']);
        Http::fake(fn () => throw new ConnectionException('cURL error 7'));

        $this->artisan('internat:myway-diagnostic')
            ->expectsOutputToContain('Fournisseur actif                       : approuve')
            ->expectsOutputToContain('INTERNAT_MODE_DEMO=true')
            ->expectsOutputToContain('MYWAY_CONNECTEUR_JETON')
            ->expectsOutputToContain('Connecteur injoignable')
            ->assertExitCode(1);
    }

    public function test_le_diagnostic_signale_un_connecteur_qui_ne_vise_pas_myway(): void
    {
        Http::fake([self::URL.'/sante' => Http::response([
            'statut' => 'ok', 'service' => 'myway-connector', 'version' => '0.1.0', 'comptes_autorises' => 2,
            'cible' => ['site' => 'http://127.0.0.1:4010', 'backend' => 'http://127.0.0.1:4011', 'officielle' => false],
        ])]);

        $this->artisan('internat:myway-diagnostic')
            ->expectsOutputToContain('banc d\'essai')
            ->assertExitCode(1);
    }

    public function test_un_compte_internat_deja_active_n_est_annonce_qu_au_titulaire_et_n_est_jamais_ecrase(): void
    {
        $compte = Stagiaire::factory()->create(['email' => self::COMPTE, 'cef' => '2026900000021']);
        $empreinte = $compte->mot_de_passe_hash;

        Http::fake([self::URL.'/*' => Http::sequence()
            ->push(['code' => 'identifiants_invalides', 'details' => ['etape' => 'connexion']], 422)
            ->push($this->contrat('verification-complete'), 200)]);

        // Sans preuve d'identité, rien ne dit qu'un compte existe pour cette adresse.
        $this->premiereConnexion()->assertStatus(422)->assertJsonPath('code', 'identifiants_invalides');

        // Une fois le titulaire vérifié, il est renvoyé vers la connexion habituelle ; aucune autorisation d'activation n'est délivrée.
        $this->premiereConnexion()->assertStatus(409)->assertJsonPath('code', 'compte_existant');

        $this->assertSame(0, VerificationIdentite::count());
        $this->assertSame($empreinte, $compte->fresh()->mot_de_passe_hash);
    }

    public function test_le_simulateur_marque_lui_aussi_l_origine_de_l_identite(): void
    {
        config(['internat.myway.fournisseur' => 'mock']);

        $this->premiereConnexion('salma.bennani@myway.test', 'MyWay-Demo-2026')
            ->assertCreated()
            ->assertJsonPath('simulee', true)
            ->assertJsonPath('profil.statut_candidature', 'Inscrit Payé');

        $this->assertSame('mock', VerificationIdentite::firstOrFail()->fournisseur);
        Http::assertNothingSent();
    }
}
