<?php

namespace Tests\Feature;

use App\Domain\Shared\Parametres;
use App\Enums\DomaineReclamation;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Enums\StatutMenu;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservation;
use App\Enums\TypeDocument;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use App\Livewire\Cloche;
use App\Livewire\Notifications;
use App\Livewire\Stagiaire\Dossier;
use App\Livewire\Stagiaire\Paiements;
use App\Livewire\Stagiaire\Profil;
use App\Livewire\Stagiaire\Reclamations;
use App\Livewire\Stagiaire\Repas;
use App\Livewire\Stagiaire\Sorties;
use App\Models\Demande;
use App\Models\DemandeSortie;
use App\Models\Document;
use App\Models\Menu;
use App\Models\NotificationInterne;
use App\Models\Parametre;
use App\Models\Reclamation;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use App\Models\Stagiaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Espace du stagiaire dans la nouvelle interface : chaque écran s'ouvre dans les
 * états qu'il rencontre, et ses actions passent par les mêmes règles de gestion
 * qu'avant. Un stagiaire ne voit et ne modifie jamais que ce qui lui appartient.
 */
class EspaceStagiaireTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private const ECRANS = ['accueil', 'inscription', 'dossier', 'profil', 'hebergement', 'paiements', 'repas', 'sorties', 'reclamations', 'documents'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private function pdf(string $nom = 'piece.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    private function ouvrirTousLesEcrans(Stagiaire $stagiaire): void
    {
        $this->connecter($stagiaire);

        foreach (self::ECRANS as $ecran) {
            $this->get(route('stagiaire.'.$ecran))->assertOk();
        }

        $this->get(route('notifications'))->assertOk();
    }

    public function test_tous_les_ecrans_s_ouvrent_sans_dossier_puis_avec_un_brouillon(): void
    {
        $stagiaire = $this->creerStagiaire($this->filles);
        $this->ouvrirTousLesEcrans($stagiaire);

        $this->creerCampagne($this->filles);
        $this->get(route('stagiaire.dossier'))->assertOk()->assertSee('Une campagne est ouverte pour votre internat');

        Livewire::actingAs($stagiaire, 'stagiaire')->test(Dossier::class)->call('ouvrir')->assertHasNoErrors();
        $this->assertSame(StatutDemande::Brouillon, $stagiaire->demandeCourante()->statut);

        $this->ouvrirTousLesEcrans($stagiaire);
    }

    public function test_tous_les_ecrans_s_ouvrent_pour_un_resident(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $affectation = $this->creerResident($campagne, $this->creerLits($this->filles)->first());
        $stagiaire = $affectation->demande->stagiaire;

        Menu::create([
            'internat_id' => $this->filles->id, 'date_repas' => now()->addDays(2)->toDateString(), 'type_repas' => TypeRepas::Dejeuner,
            'description' => 'Tajine de légumes', 'statut' => StatutMenu::Valide, 'cree_par' => $this->restauration->id,
        ]);
        Storage::disk('prive')->put('documents/reglement.pdf', '%PDF-1.4');
        $document = Document::create([
            'internat_id' => $this->filles->id, 'titre' => 'Règlement intérieur', 'type' => TypeDocument::cases()[0], 'fichier' => 'documents/reglement.pdf',
            'nom_original' => 'reglement.pdf', 'mime' => 'application/pdf', 'date_publication' => now(), 'publie_par' => $this->gestionnaireFilles->id,
        ]);

        $this->ouvrirTousLesEcrans($stagiaire);

        $this->get(route('stagiaire.hebergement'))->assertSee('Mon affectation')->assertSee('Arrivée enregistrée');
        $this->get(route('stagiaire.repas'))->assertSee('Tajine de légumes');
        $this->get(route('stagiaire.documents'))->assertSee('Règlement intérieur');
        $this->get(route('stagiaire.documents.fichier', $document))->assertOk();
        $this->get(route('stagiaire.hebergement.attestation'))->assertOk();
        $this->get(route('stagiaire.dossier.accuse'))->assertOk();
    }

    public function test_le_stagiaire_constitue_et_depose_son_dossier_depuis_l_ecran(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerStagiaire($this->filles, ['adresse' => '12 rue des Orangers, Hay Hassani']);
        $cin = $campagne->typesPieces->firstWhere('obligatoire', true);

        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(Dossier::class)->call('ouvrir');

        // L'adresse déclarée à l'inscription est reprise : elle n'est pas à ressaisir.
        $ecran->assertSet('situation.adresse_complete', '12 rue des Orangers, Hay Hassani')
            ->set('situation.eloigne_ou_rural', true)
            ->call('enregistrer', true)
            ->assertHasNoErrors()
            ->assertSet('etape', 2);

        // Sans la pièce obligatoire, le dépôt est refusé par le domaine.
        $ecran->call('soumettre');
        $this->assertSame(StatutDemande::Brouillon, $stagiaire->demandeCourante()->statut);

        $ecran->set("fichiers.{$cin->id}", $this->pdf('cin.pdf'));
        $piece = $stagiaire->demandeCourante()->pieces()->sole();
        $this->assertSame('cin.pdf', $piece->nom_original);
        Storage::disk('prive')->assertExists($piece->fichier);
        $this->connecter($stagiaire)->get(route('stagiaire.dossier.piece', $piece))->assertOk();

        $ecran->call('soumettre')->assertHasNoErrors();

        $demande = $stagiaire->demandeCourante();
        $this->assertSame(StatutDemande::Recu, $demande->statut);
        $this->assertNotNull($demande->numero_dossier);
        $this->assertTrue($demande->eloigne_ou_rural);
        $this->get(route('stagiaire.dossier'))->assertOk()->assertSee($demande->numero_dossier)->assertSee('Dossier reçu, en cours d’instruction');
    }

    public function test_le_depot_est_refuse_tant_que_l_inscription_n_est_pas_soumise(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerStagiaire($this->filles, ['statut_inscription' => StatutInscription::Declaree, 'inscription_verifiee_le' => null, 'adresse' => '12 rue des Orangers']);

        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(Dossier::class)->call('ouvrir')->call('enregistrer', true);
        $ecran->set('fichiers.'.$campagne->typesPieces->firstWhere('obligatoire', true)->id, $this->pdf())->call('soumettre');

        $this->assertSame(StatutDemande::Brouillon, $stagiaire->demandeCourante()->statut);
        $this->connecter($stagiaire)->get(route('stagiaire.dossier'))->assertSee('n’a pas encore été soumise à la vérification');
    }

    public function test_un_admis_confirme_sa_place_ou_y_renonce(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $admis = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id, 'date_limite_confirmation' => now()->addDays(5),
        ]);

        Livewire::actingAs($admis->stagiaire, 'stagiaire')->test(Dossier::class)->assertSee('Vous êtes admis à l’internat')->call('confirmer')->assertHasNoErrors();
        $this->assertSame(StatutDemande::Confirme, $admis->fresh()->statut);

        $ecran = Livewire::actingAs($admis->stagiaire, 'stagiaire')->test(Dossier::class);
        $ecran->set('raison', 'cout')->set('explication', 'court')->call('desister')->assertHasErrors('explication');
        $ecran->set('explication', 'Je ne peux pas assumer les frais mensuels cette année.')->call('desister')->assertHasNoErrors();

        $this->assertSame(StatutDemande::Desiste, $admis->fresh()->statut);
    }

    public function test_le_resident_depose_le_recu_de_son_paiement_d_hebergement(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $demande = $this->creerConfirme($campagne);
        $paiement = $demande->paiements()->sole();
        $autre = $this->creerConfirme($campagne)->paiements()->sole();

        $ecran = Livewire::actingAs($demande->stagiaire, 'stagiaire')->test(Paiements::class)->assertSee('Autorisé');
        $ecran->set("recus.{$paiement->id}", $this->pdf('recu.pdf'));

        $this->assertSame(StatutPaiement::RecuDepose, $paiement->fresh()->statut);
        $this->connecter($demande->stagiaire)->get(route('stagiaire.paiements.recu', $paiement))->assertOk();
        $this->get(route('stagiaire.paiements.autorisation', $paiement))->assertOk();

        // Le paiement d'un autre résident n'existe pas pour lui.
        $this->get(route('stagiaire.paiements.autorisation', $autre))->assertNotFound();
        $this->get(route('stagiaire.paiements.recu', $autre))->assertNotFound();
        Livewire::actingAs($demande->stagiaire, 'stagiaire')->test(Paiements::class)->call('voirRecu', $autre->id)->assertNotFound();

        // Régler l'hébergement ne dit rien de l'inscription en formation, et inversement.
        $this->assertTrue($demande->stagiaire->fresh()->inscriptionVerifiee());
    }

    public function test_le_resident_reserve_un_repas_et_declare_un_regime(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerResident($campagne, $this->creerLits($this->filles)->first())->demande->stagiaire;
        $menu = Menu::create([
            'internat_id' => $this->filles->id, 'date_repas' => now()->addDays(3)->toDateString(), 'type_repas' => TypeRepas::Diner,
            'description' => 'Couscous', 'statut' => StatutMenu::Valide, 'cree_par' => $this->restauration->id,
        ]);

        // Sans reçu déposé, la restauration est bloquée à partir du 5 du mois : la règle est levée pour ce test.
        Parametre::where('cle', 'restauration.critere_blocage')->update(['valeur' => json_encode('aucun')]);
        app(Parametres::class)->oublier();

        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(Repas::class)->assertSee('Couscous');
        $ecran->call('reserver', $menu->id)->assertHasNoErrors();

        $reservation = ReservationRepas::where('stagiaire_id', $stagiaire->id)->sole();
        $this->assertSame(StatutReservation::Reserve, $reservation->statut);

        $ecran->call('annuler', $reservation->id);
        $this->assertSame(StatutReservation::Annule, $reservation->fresh()->statut);

        // Une allergie exige un justificatif ; une restriction religieuse, non.
        $ecran->set('type', TypeRegime::Allergie->value)->call('declarerRegime')->assertHasErrors('justificatif');
        $ecran->set('type', TypeRegime::RestrictionReligieuse->value)->set('precision', 'Sans porc')->call('declarerRegime')->assertHasNoErrors();
        $regime = RegimeAlimentaire::where('stagiaire_id', $stagiaire->id)->sole();

        $ecran->set('type', TypeRegime::RestrictionReligieuse->value)->call('declarerRegime')->assertHasErrors('type');
        $ecran->call('retirerRegime', $regime->id);
        $this->assertSame(0, RegimeAlimentaire::count());
    }

    public function test_le_resident_demande_une_sortie_et_depose_une_reclamation(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $stagiaire = $this->creerResident($campagne, $this->creerLits($this->filles)->first())->demande->stagiaire;

        $sorties = Livewire::actingAs($stagiaire, 'stagiaire')->test(Sorties::class);
        $sorties->set('date_depart', now()->addDays(2)->format('Y-m-d\TH:i'))->set('date_retour', now()->addDay()->format('Y-m-d\TH:i'))->set('motif', 'Visite familiale')
            ->call('deposer')->assertHasErrors('date_retour');
        $sorties->set('date_retour', now()->addDays(4)->format('Y-m-d\TH:i'))->call('deposer')->assertHasNoErrors();
        $this->assertSame(1, DemandeSortie::count());

        $reclamations = Livewire::actingAs($stagiaire, 'stagiaire')->test(Reclamations::class);
        $reclamations->set('domaine', 'inconnu')->set('description', 'Le chauffage de la chambre ne fonctionne plus.')->call('deposer')->assertHasErrors('domaine');
        $reclamations->set('domaine', DomaineReclamation::Hebergement->value)->call('deposer')->assertHasNoErrors()->assertSee('Le chauffage de la chambre');
        $this->assertSame($stagiaire->id, Reclamation::sole()->stagiaire_id);
    }

    public function test_le_stagiaire_change_son_mot_de_passe(): void
    {
        $stagiaire = $this->creerStagiaire($this->filles);
        $ecran = Livewire::actingAs($stagiaire, 'stagiaire')->test(Profil::class);

        $ecran->set('actuel', 'Pas-Le-Bon-Motdepasse-1')->set('mot_de_passe', 'Nouveau-Secret-2026')->set('mot_de_passe_confirmation', 'Nouveau-Secret-2026')
            ->call('changerMotDePasse')->assertHasErrors('actuel');
        $ecran->set('actuel', 'Motdepasse-Test-1')->set('mot_de_passe', 'faible')->set('mot_de_passe_confirmation', 'faible')
            ->call('changerMotDePasse')->assertHasErrors('mot_de_passe');
        $this->assertTrue(Hash::check('Motdepasse-Test-1', $stagiaire->fresh()->mot_de_passe_hash));

        $ecran->set('actuel', 'Motdepasse-Test-1')->set('mot_de_passe', 'Nouveau-Secret-2026')->set('mot_de_passe_confirmation', 'Nouveau-Secret-2026')
            ->call('changerMotDePasse')->assertHasNoErrors();

        $this->assertTrue(Hash::check('Nouveau-Secret-2026', $stagiaire->fresh()->mot_de_passe_hash));
        $this->assertStringStartsWith('$argon2id$', $stagiaire->fresh()->mot_de_passe_hash);
    }

    public function test_les_notifications_sont_celles_du_compte_et_d_aucun_autre(): void
    {
        $stagiaire = $this->creerStagiaire($this->filles);
        $autre = $this->creerStagiaire($this->filles);

        $mienne = NotificationInterne::create(['stagiaire_id' => $stagiaire->id, 'type' => 'information', 'titre' => 'Pour moi', 'message' => 'Message', 'lien' => '/stagiaire/dossier', 'date_envoi' => now()]);
        $sienne = NotificationInterne::create(['stagiaire_id' => $autre->id, 'type' => 'information', 'titre' => 'Pour une autre', 'message' => 'Message', 'date_envoi' => now()]);

        Livewire::actingAs($stagiaire, 'stagiaire')->test(Cloche::class)->assertSee('Pour moi')->assertDontSee('Pour une autre')
            ->call('ouvrir', $sienne->id)->assertNotFound();
        $this->assertFalse($sienne->fresh()->lue);

        Livewire::actingAs($stagiaire, 'stagiaire')->test(Notifications::class)->assertSee('Pour moi')->assertDontSee('Pour une autre')
            ->call('ouvrir', $mienne->id)->assertRedirect('/stagiaire/dossier');
        $this->assertTrue($mienne->fresh()->lue);

        Livewire::actingAs($stagiaire, 'stagiaire')->test(Notifications::class)->call('lire');
        $this->assertFalse($sienne->fresh()->lue);
    }
}
