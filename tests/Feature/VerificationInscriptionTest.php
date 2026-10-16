<?php

namespace Tests\Feature;

use App\Domain\Admissions\Classement;
use App\Domain\Applications\DossierStagiaire;
use App\Domain\Applications\Instruction;
use App\Domain\Identity\ControleInscription;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\OrigineVerificationInscription;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Enums\StatutPiece;
use App\Enums\StatutVerificationInscription;
use App\Livewire\Personnel\InscriptionFiche;
use App\Livewire\Personnel\Inscriptions;
use App\Livewire\Stagiaire\MonInscription;
use App\Models\Demande;
use App\Models\JournalAudit;
use App\Models\NotificationInterne;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\Stagiaire;
use App\Models\VerificationInscription;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Vérification administrative de l'inscription en formation — ce qui remplace
 * le contrôle « Inscrit Payé » de MyWay. Déclarer, cocher ou déposer un fichier
 * ne rend jamais éligible : seule la décision d'un agent habilité, dans son
 * périmètre, le fait.
 */
class VerificationInscriptionTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private function declare(array $attributs = []): Stagiaire
    {
        return Stagiaire::factory()->inscription(StatutInscription::Declaree)->create($attributs + [
            'etablissement_id' => $this->etablissement->id, 'genre' => $this->filles->genre,
        ]);
    }

    private function justificatif(string $nom = 'attestation.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    private function soumettre(Stagiaire $stagiaire): VerificationInscription
    {
        return app(ControleInscription::class)->soumettre($stagiaire, $this->justificatif());
    }

    /* -------------------------------------------------------- Soumission */

    public function test_le_stagiaire_soumet_son_inscription_avec_un_justificatif(): void
    {
        $stagiaire = $this->declare();

        Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class)
            ->assertSet('etape', 'verification')
            ->set('justificatif', $this->justificatif())
            ->call('soumettre')
            ->assertHasNoErrors()
            ->assertSee('En attente de vérification');

        $verification = VerificationInscription::sole();

        $this->assertSame(StatutVerificationInscription::Soumise, $verification->statut);
        $this->assertSame(OrigineVerificationInscription::Declaration, $verification->origine);
        $this->assertSame($stagiaire->cef, $verification->cef);
        $this->assertSame($this->etablissement->id, $verification->etablissement_id);
        $this->assertSame('attestation.pdf', $verification->nom_original);
        $this->assertNull($verification->decidee_par);
        Storage::disk('prive')->assertExists($verification->fichier);
        $this->assertStringStartsWith("inscriptions/{$stagiaire->id}/", $verification->fichier);

        // Déposer un justificatif ne vérifie rien.
        $stagiaire->refresh();
        $this->assertSame(StatutInscription::Soumise, $stagiaire->statut_inscription);
        $this->assertFalse($stagiaire->inscriptionVerifiee());
        $this->assertNull($stagiaire->inscription_verifiee_le);

        // Les agents du périmètre sont prévenus : gestionnaire de l'internat du genre, chef de pôle de l'établissement.
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->gestionnaireFilles->id)->where('titre', 'Inscription à vérifier')->exists());
        $this->assertTrue(NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('titre', 'Inscription à vérifier')->exists());
        $this->assertFalse(NotificationInterne::where('utilisateur_id', $this->gestionnaireGarcons->id)->exists());
        $this->assertFalse(NotificationInterne::where('utilisateur_id', $this->gestionnaireAilleurs->id)->exists());
    }

    public function test_le_justificatif_est_exige_et_son_format_controle(): void
    {
        $stagiaire = $this->declare();
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(MonInscription::class);

        $ecran->call('soumettre')->assertHasErrors('justificatif');

        // Extension refusée.
        $ecran->set('justificatif', UploadedFile::fake()->createWithContent('notes.txt', 'texte'))->call('soumettre');

        // Contenu qui ne correspond pas à l'extension annoncée : le type réel est lu dans le fichier, pas dans son nom.
        $chemin = tempnam(sys_get_temp_dir(), 'internat');
        file_put_contents($chemin, '<?php echo "pas un PDF";');
        $this->assertRegle('format_refuse', fn () => app(ControleInscription::class)->soumettre($stagiaire, new UploadedFile($chemin, 'attestation.pdf', null, null, true)));
        @unlink($chemin);

        $this->assertSame(0, VerificationInscription::count());
        $this->assertSame(StatutInscription::Declaree, $stagiaire->fresh()->statut_inscription);
        $this->assertSame([], Storage::disk('prive')->allFiles('inscriptions'));
    }

    public function test_un_profil_incomplet_ou_une_adresse_non_confirmee_ne_peuvent_pas_soumettre(): void
    {
        $controle = app(ControleInscription::class);

        $incomplet = Stagiaire::factory()->nouveau()->create();
        $this->assertRegle('profil_incomplet', fn () => $controle->soumettre($incomplet, $this->justificatif()));

        config(['internat.inscription.verifier_adresse' => true]);
        $nonConfirme = $this->declare(['email_verifie_le' => null]);
        $this->assertRegle('adresse_non_verifiee', fn () => $controle->soumettre($nonConfirme, $this->justificatif()));

        $this->assertSame(0, VerificationInscription::count());
    }

    public function test_une_fois_soumise_la_declaration_est_figee_sauf_les_coordonnees(): void
    {
        $stagiaire = $this->declare();
        $this->soumettre($stagiaire);
        $cef = $stagiaire->cef;

        $ecran = Livewire::actingAs($stagiaire->fresh(), 'stagiaire')->test(MonInscription::class);

        $ecran->set('identite.cef', '2026999999999')->set('identite.nom', 'Autre')->set('identite.ville', 'Rabat')->call('enregistrerIdentite')->assertHasNoErrors();
        $ecran->set('formation.nom_filiere', 'Autre filière')->call('enregistrerFormation');

        $stagiaire->refresh();
        $this->assertSame($cef, $stagiaire->cef);
        $this->assertNotSame('Autre', $stagiaire->nom);
        $this->assertSame('Rabat', $stagiaire->ville);
        $this->assertSame('Développement digital', $stagiaire->nom_filiere);
    }

    public function test_le_stagiaire_peut_retirer_sa_demande_pour_la_corriger(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);

        Livewire::actingAs($stagiaire->fresh(), 'stagiaire')->test(MonInscription::class)->call('retirer')->assertHasNoErrors();

        $this->assertSame(StatutVerificationInscription::Remplacee, $demande->fresh()->statut);
        $this->assertSame(StatutInscription::Declaree, $stagiaire->fresh()->statut_inscription);
    }

    /* ---------------------------------------------------------- Décisions */

    public function test_un_agent_habilite_verifie_l_inscription_et_la_decision_est_tracee(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);

        Livewire::actingAs($this->gestionnaireFilles)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire])
            ->assertSee('Demande en attente')
            ->call('decider', 'verifiee')
            ->assertHasNoErrors()
            ->assertDontSee('Demande en attente');

        $demande->refresh();
        $stagiaire->refresh();

        $this->assertSame(StatutVerificationInscription::Verifiee, $demande->statut);
        $this->assertSame($this->gestionnaireFilles->id, $demande->decidee_par);
        $this->assertNotNull($demande->decidee_le);
        $this->assertTrue($stagiaire->inscriptionVerifiee());
        $this->assertNotNull($stagiaire->inscription_verifiee_le);

        $trace = JournalAudit::where('action', 'inscription.verifiee')->sole();
        $this->assertSame($this->gestionnaireFilles->id, $trace->utilisateur_id);
        $this->assertSame($demande->id, $trace->entite_id);
        $this->assertTrue(NotificationInterne::where('stagiaire_id', $stagiaire->id)->where('titre', 'Inscription vérifiée')->exists());

        // Vérifier une inscription ne règle rien de l'hébergement : aucun paiement n'est créé ni validé.
        $this->assertSame(0, Paiement::count());
    }

    public function test_un_rejet_ou_une_demande_de_correction_exigent_un_motif_communique_au_stagiaire(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);
        $fiche = Livewire::actingAs($this->chefDePole)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire]);

        $fiche->call('decider', 'rejetee', '');
        $fiche->call('decider', 'a_corriger', 'non');
        $this->assertSame(StatutVerificationInscription::Soumise, $demande->fresh()->statut);

        $fiche->call('decider', 'a_corriger', 'L\'attestation ne porte pas l\'année scolaire en cours.');

        $this->assertSame(StatutVerificationInscription::ACorriger, $demande->fresh()->statut);
        $this->assertSame(StatutInscription::ACorriger, $stagiaire->fresh()->statut_inscription);
        $this->assertSame('L\'attestation ne porte pas l\'année scolaire en cours.', $demande->fresh()->motif);
        $this->assertTrue(NotificationInterne::where('stagiaire_id', $stagiaire->id)->where('message', 'like', '%année scolaire en cours%')->exists());

        // Le stagiaire corrige et soumet de nouveau ; l'ancienne demande reste dans l'historique.
        $seconde = $this->soumettre($stagiaire->fresh());
        Livewire::actingAs($this->chefDePole)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire])
            ->call('decider', 'rejetee', 'Le reçu présenté concerne une autre personne.');

        $this->assertSame(StatutVerificationInscription::Rejetee, $seconde->fresh()->statut);
        $this->assertSame(StatutInscription::Rejetee, $stagiaire->fresh()->statut_inscription);
        $this->assertNull($stagiaire->fresh()->inscription_verifiee_le);
        $this->assertSame(2, VerificationInscription::where('stagiaire_id', $stagiaire->id)->count());
    }

    public function test_une_demande_n_est_tranchee_qu_une_fois(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);
        $controle = app(ControleInscription::class);

        $controle->decider($demande, StatutVerificationInscription::Verifiee, null, $this->gestionnaireFilles);

        $this->assertRegle('verification_deja_traitee', fn () => $controle->decider($demande, StatutVerificationInscription::Rejetee, 'Second avis contraire', $this->chefDePole));
        $this->assertTrue($stagiaire->fresh()->inscriptionVerifiee());
    }

    public function test_un_agent_peut_verifier_sur_le_registre_sans_justificatif_avec_un_motif(): void
    {
        $stagiaire = $this->declare();
        $fiche = Livewire::actingAs($this->chefDePole)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire]);

        $fiche->call('deciderSansDemande', 'verifiee', '');
        $this->assertFalse($stagiaire->fresh()->inscriptionVerifiee());

        $fiche->call('deciderSansDemande', 'verifiee', 'Inscrit au registre de scolarité, frais acquittés le 02/10.');

        $ligne = VerificationInscription::sole();
        $this->assertSame(OrigineVerificationInscription::Administration, $ligne->origine);
        $this->assertSame($this->chefDePole->id, $ligne->decidee_par);
        $this->assertNull($ligne->fichier);
        $this->assertTrue($stagiaire->fresh()->inscriptionVerifiee());
    }

    /* ------------------------------------------------- Accès et périmètre */

    public function test_seul_un_agent_habilite_de_son_perimetre_peut_decider(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);
        $controle = app(ControleInscription::class);

        // Autre établissement, autre genre, rôles sans la permission.
        foreach ([$this->gestionnaireAilleurs, $this->gestionnaireGarcons, $this->admin, $this->restauration, $this->directeur, $this->directionRegionale, $this->directionCentrale] as $compte) {
            $this->assertFalse($controle->couvre($compte, $stagiaire), $compte->roleCode()->value);

            try {
                $controle->decider($demande, StatutVerificationInscription::Verifiee, null, $compte);
                $this->fail('Décision acceptée pour '.$compte->roleCode()->value);
            } catch (AuthorizationException) {
                $this->assertSame(StatutVerificationInscription::Soumise, $demande->fresh()->statut);
            }
        }

        $this->assertTrue($controle->couvre($this->gestionnaireFilles, $stagiaire));
        $this->assertTrue($controle->couvre($this->chefDePole, $stagiaire));
        $this->assertFalse($stagiaire->fresh()->inscriptionVerifiee());
    }

    public function test_les_ecrans_de_verification_sont_fermes_hors_permission_et_hors_perimetre(): void
    {
        $stagiaire = $this->declare();
        $demande = $this->soumettre($stagiaire);

        // Sans la permission : ni la liste, ni la fiche, ni le justificatif.
        foreach ([$this->admin, $this->restauration, $this->directionCentrale] as $compte) {
            $this->actingAs($compte)->get(route('inscriptions'))->assertForbidden();
            $this->actingAs($compte)->get(route('inscriptions.fiche', $stagiaire))->assertForbidden();
            $this->actingAs($compte)->get(route('inscriptions.justificatif', $demande))->assertForbidden();
        }

        // Avec la permission mais hors périmètre : la fiche n'existe pas, le justificatif est refusé.
        $this->actingAs($this->gestionnaireAilleurs)->get(route('inscriptions.fiche', $stagiaire))->assertNotFound();
        $this->actingAs($this->gestionnaireAilleurs)->get(route('inscriptions.justificatif', $demande))->assertForbidden();
        Livewire::actingAs($this->gestionnaireAilleurs)->test(Inscriptions::class)->assertDontSee($stagiaire->nom);

        // Dans le périmètre.
        $this->actingAs($this->gestionnaireFilles)->get(route('inscriptions.fiche', $stagiaire))->assertOk()->assertSee($stagiaire->cef);
        $this->actingAs($this->gestionnaireFilles)->get(route('inscriptions.justificatif', $demande))->assertOk();
        Livewire::actingAs($this->gestionnaireFilles)->test(Inscriptions::class)->assertSee($stagiaire->nom);

        // Un stagiaire n'ouvre jamais ces écrans, et ne lit que son propre justificatif.
        $autre = $this->declare();
        $this->actingAs($autre, 'stagiaire')->get(route('stagiaire.inscription.justificatif', $demande))->assertNotFound();
        $this->actingAs($stagiaire->fresh(), 'stagiaire')->get(route('stagiaire.inscription.justificatif', $demande))->assertOk();
    }

    /* ------------------------------------------ Conséquences sur le dossier */

    public function test_sans_inscription_soumise_le_dossier_ne_peut_pas_etre_depose(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $dossiers = app(DossierStagiaire::class);

        foreach ([StatutInscription::Declaree, StatutInscription::ACorriger, StatutInscription::Rejetee] as $statut) {
            $stagiaire = $this->creerStagiaire($this->filles, ['statut_inscription' => $statut, 'inscription_verifiee_le' => null]);

            if ($statut === StatutInscription::Rejetee) {
                $this->assertRegle('inscription_rejetee', fn () => $dossiers->ouvrirBrouillon($stagiaire));

                continue;
            }

            // Le brouillon reste possible : le stagiaire prépare son dossier en attendant.
            $demande = $dossiers->ouvrirBrouillon($stagiaire);
            $dossiers->enregistrer($demande, ['adresse_complete' => '12 rue des Orangers']);
            $this->joindrePiece($demande, $campagne->typesPieces->firstWhere('obligatoire', true));

            $this->assertRegle('inscription_non_verifiee', fn () => $dossiers->soumettre($demande->fresh()));
            $this->assertSame(StatutDemande::Brouillon, $demande->fresh()->statut);
        }
    }

    public function test_un_dossier_depose_n_entre_dans_la_selection_qu_une_fois_l_inscription_verifiee(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerStagiaire($this->filles, ['statut_inscription' => StatutInscription::Declaree, 'inscription_verifiee_le' => null]);
        $demandeInscription = $this->soumettre($stagiaire);

        // Inscription soumise : le dépôt est admis, pour ne pas faire manquer l'échéance de la campagne.
        $dossiers = app(DossierStagiaire::class);
        $demande = $dossiers->ouvrirBrouillon($stagiaire->fresh());
        $dossiers->enregistrer($demande, ['adresse_complete' => '12 rue des Orangers']);
        $piece = $this->joindrePiece($demande, $campagne->typesPieces->firstWhere('obligatoire', true));
        $demande = $dossiers->soumettre($demande->fresh());
        $this->assertSame(StatutDemande::Recu, $demande->statut);

        // Mais le gestionnaire ne peut pas le déclarer complet : pas de score, pas de classement.
        $instruction = app(Instruction::class);
        $instruction->controlerPiece($piece, StatutPiece::Acceptee, null, $this->gestionnaireFilles);
        $this->assertRegle('inscription_non_verifiee', fn () => $instruction->declarerComplet($demande->fresh(), $this->gestionnaireFilles));
        $this->assertSame(StatutDemande::Recu, $demande->fresh()->statut);
        $this->assertNull($demande->fresh()->score);

        app(ControleInscription::class)->decider($demandeInscription, StatutVerificationInscription::Verifiee, null, $this->chefDePole);

        $instruction->declarerComplet($demande->fresh(), $this->gestionnaireFilles);
        $this->assertSame(StatutDemande::Complet, $demande->fresh()->statut);
        $this->assertNotNull($demande->fresh()->score);
    }

    public function test_le_parametre_peut_exiger_la_verification_avant_tout_depot(): void
    {
        Parametre::where('cle', 'inscription.depot_exige_verification')->update(['valeur' => json_encode(true)]);
        app(Parametres::class)->oublier();

        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerStagiaire($this->filles, ['statut_inscription' => StatutInscription::Declaree, 'inscription_verifiee_le' => null]);
        $this->soumettre($stagiaire);

        $dossiers = app(DossierStagiaire::class);
        $demande = $dossiers->ouvrirBrouillon($stagiaire->fresh());
        $dossiers->enregistrer($demande, ['adresse_complete' => '12 rue des Orangers']);
        $this->joindrePiece($demande, $campagne->typesPieces->firstWhere('obligatoire', true));

        $this->assertRegle('inscription_non_verifiee', fn () => $dossiers->soumettre($demande->fresh()));
    }

    public function test_le_classement_ignore_un_dossier_dont_l_inscription_n_est_pas_verifiee(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 5]);
        $verifie = $this->creerDossierRecu($campagne);
        $douteux = $this->creerDossierRecu($campagne);

        // Garde-fou : même si un dossier se retrouvait « complet » sans inscription vérifiée, il ne serait pas classé.
        Demande::whereKey([$verifie->id, $douteux->id])->update(['statut' => StatutDemande::Complet->value, 'score' => 30]);
        Stagiaire::whereKey($douteux->stagiaire_id)->update(['statut_inscription' => StatutInscription::Soumise->value, 'inscription_verifiee_le' => null]);

        $resultat = app(Classement::class)->recalculer($campagne);

        $this->assertSame(1, $resultat['classes']);
        $this->assertSame(1, $verifie->fresh()->rang);
        $this->assertNull($douteux->fresh()->rang);
        $this->assertNull($douteux->fresh()->liste);
    }

    public function test_remettre_en_cause_une_inscription_retire_le_dossier_complet_de_la_selection(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->creerDossierRecu($campagne);
        $stagiaire = $demande->stagiaire;
        Demande::whereKey($demande->id)->update(['statut' => StatutDemande::Complet->value, 'score' => 30, 'rang' => 1]);

        Livewire::actingAs($this->chefDePole)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire])
            ->call('deciderSansDemande', 'rejetee', 'Radié du registre de scolarité le 05/10.');

        $demande->refresh();
        $this->assertSame(StatutInscription::Rejetee, $stagiaire->fresh()->statut_inscription);
        $this->assertSame(StatutDemande::Recu, $demande->statut);
        $this->assertNull($demande->score);
        $this->assertNull($demande->rang);
        $this->assertTrue($demande->historique()->where('nouveau_statut', StatutDemande::Recu->value)->where('motif', 'like', '%remise en cause%')->exists());
    }

    public function test_une_inscription_ne_peut_plus_etre_remise_en_cause_ici_apres_la_deliberation(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->creerDossierRecu($campagne);
        Demande::whereKey($demande->id)->update(['statut' => StatutDemande::Admis->value]);

        $this->assertRegle('dossier_delibere', fn () => app(ControleInscription::class)->deciderSansDemande(
            $demande->stagiaire, StatutVerificationInscription::Rejetee, 'Radié du registre.', $this->chefDePole,
        ));

        $this->assertTrue($demande->stagiaire->fresh()->inscriptionVerifiee());
        $this->assertSame(StatutDemande::Admis, $demande->fresh()->statut);
    }

    /* --------------------------------------------- Reprise de l'existant */

    public function test_une_inscription_reprise_de_myway_reste_verifiee_et_attend_la_confirmation_d_un_agent(): void
    {
        // État laissé par la migration pour un compte créé lors de l'ancienne première connexion.
        $stagiaire = $this->creerStagiaire($this->filles, ['source_identite' => 'connecteur', 'statut_myway' => 'Inscrit Payé']);
        $reprise = VerificationInscription::create([
            'stagiaire_id' => $stagiaire->id, 'origine' => OrigineVerificationInscription::Reprise, 'statut' => StatutVerificationInscription::Verifiee,
            'cef' => $stagiaire->cef, 'etablissement_id' => $stagiaire->etablissement_id, 'soumise_le' => now(), 'decidee_le' => now(), 'motif' => 'Reprise',
        ]);

        $this->assertTrue($stagiaire->fresh()->inscriptionVerifiee());
        $this->assertSame(1, VerificationInscription::reprisesAReexaminer()->count());

        Livewire::actingAs($this->gestionnaireFilles)->test(Inscriptions::class)->call('voir', 'reprises')->assertSee($stagiaire->nom);

        Livewire::actingAs($this->gestionnaireFilles)->test(InscriptionFiche::class, ['stagiaire' => $stagiaire])
            ->assertSee('jamais contrôlée par un agent')
            ->call('deciderSansDemande', 'verifiee', 'Confirmé sur la liste des inscrits de l\'établissement.');

        // La reprise reste dans l'historique ; la confirmation la suit, signée par l'agent.
        $this->assertNull($reprise->fresh()->decidee_par);
        $this->assertSame($this->gestionnaireFilles->id, VerificationInscription::where('origine', 'administration')->sole()->decidee_par);
        $this->assertTrue($stagiaire->fresh()->inscriptionVerifiee());
        Livewire::actingAs($this->gestionnaireFilles)->test(Inscriptions::class)->call('voir', 'reprises')->assertDontSee($stagiaire->nom);
    }
}
