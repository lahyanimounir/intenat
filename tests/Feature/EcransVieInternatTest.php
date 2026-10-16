<?php

namespace Tests\Feature;

use App\Domain\Residence\VieInternat;
use App\Enums\DomaineReclamation;
use App\Enums\StatutChambre;
use App\Enums\StatutMaintenance;
use App\Enums\StatutReclamation;
use App\Enums\StatutSortie;
use App\Enums\TypeDocument;
use App\Enums\TypeIncident;
use App\Livewire\Personnel\DocumentsInternat;
use App\Livewire\Personnel\Equipements;
use App\Livewire\Personnel\Incidents;
use App\Livewire\Personnel\Presences;
use App\Livewire\Personnel\Reclamations;
use App\Models\Absence;
use App\Models\Affectation;
use App\Models\DemandeMaintenance;
use App\Models\DemandeSortie;
use App\Models\Document;
use App\Models\Equipement;
use App\Models\Incident;
use App\Models\JournalAudit;
use App\Models\Reclamation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Vie à l'internat dans la nouvelle interface : sorties, appel, incidents,
 * maintenance, équipements, réclamations et documents utiles. Chaque écran est
 * tenu par le responsable de l'internat concerné, et par lui seul.
 */
class EcransVieInternatTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    private function pdf(string $nom = 'rapport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    }

    /** @return array{0: Affectation, 1: Affectation} deux résidentes de l'internat des filles */
    private function residentes(): array
    {
        $campagne = $this->creerCampagne($this->filles);
        $lits = $this->creerLits($this->filles);

        return [$this->creerResident($campagne, $lits[0]), $this->creerResident($campagne, $lits[1])];
    }

    private function sortie(Affectation $affectation, int $dansJours = 0): DemandeSortie
    {
        return DemandeSortie::create([
            'affectation_id' => $affectation->id, 'date_depart' => now()->addDays($dansJours)->startOfDay(),
            'date_retour' => now()->addDays($dansJours + 1)->endOfDay(), 'motif' => 'Visite familiale', 'statut' => StatutSortie::EnAttente,
        ]);
    }

    public function test_le_gestionnaire_decide_des_sorties_de_son_internat(): void
    {
        [$une, $deux] = $this->residentes();
        $acceptee = $this->sortie($une);
        $refusee = $this->sortie($deux);

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Presences::class)
            ->assertSee('Visite familiale')->assertSee($une->demande->stagiaire->nom);

        $ecran->call('accepter', $acceptee->id)->assertHasNoErrors();
        $this->assertSame(StatutSortie::Acceptee, $acceptee->fresh()->statut);
        $this->assertSame($this->gestionnaireFilles->id, $acceptee->fresh()->traite_par);

        // Un refus sans motif n'est pas enregistré ; le motif est transmis au résident.
        $ecran->call('refuser', $refusee->id, 'non')->assertHasErrors('motif');
        $this->assertSame(StatutSortie::EnAttente, $refusee->fresh()->statut);
        $ecran->call('refuser', $refusee->id, 'Examen le lendemain matin.')->assertHasNoErrors();
        $this->assertSame('Examen le lendemain matin.', $refusee->fresh()->motif_refus);

        // Une demande déjà traitée ne se retraite pas.
        $ecran->call('accepter', $refusee->id);
        $this->assertSame(StatutSortie::Refusee, $refusee->fresh()->statut);
    }

    public function test_une_sortie_d_un_autre_internat_ne_se_voit_ni_ne_se_traite(): void
    {
        [$une] = $this->residentes();
        $sortie = $this->sortie($une);

        foreach ([$this->gestionnaireGarcons, $this->gestionnaireAilleurs] as $compte) {
            Livewire::actingAs($compte)->test(Presences::class)->assertDontSee('Visite familiale');
            Livewire::actingAs($compte)->test(Presences::class)->call('accepter', $sortie->id)->assertForbidden();
            Livewire::actingAs($compte)->test(Presences::class)->set('internat', (string) $this->filles->id)->assertForbidden();
        }

        $this->assertSame(StatutSortie::EnAttente, $sortie->fresh()->statut);

        // Le directeur consulte le pilotage, pas la tenue quotidienne de l'internat.
        $this->connecter($this->directeur)->get('/vie-internat/presences')->assertForbidden();
        $this->connecter($this->gestionnaireFilles)->get('/vie-internat/presences')->assertOk()->assertSee('Sorties et absences');
        $this->connecter($this->gestionnaireFilles)->get('/vie-internat/absences')->assertRedirect('/vie-internat/presences?onglet=absences');
    }

    public function test_l_appel_distingue_l_absence_autorisee_de_celle_qui_alerte(): void
    {
        [$une, $deux] = $this->residentes();
        $etrangere = $this->creerResident($this->creerCampagne($this->garcons), $this->creerLits($this->garcons)->first());
        $this->agirEnTantQue($this->gestionnaireFilles);
        app(VieInternat::class)->traiterSortie($this->sortie($une), true, null, $this->gestionnaireFilles);

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Presences::class)->call('voir', 'feuille')
            ->assertSee($une->demande->stagiaire->cef)->assertSee($deux->demande->stagiaire->cef);

        // Rien de coché : rien d'enregistré.
        $ecran->call('saisir');
        $this->assertSame(0, Absence::count());

        $ecran->set('absents', [(string) $une->id, (string) $deux->id])->call('saisir')->assertHasNoErrors()
            ->assertSee('Absent — autorisé')->assertSee('Absent — non autorisé');

        $this->assertTrue(Absence::where('affectation_id', $une->id)->sole()->autorisee);
        $this->assertFalse(Absence::where('affectation_id', $deux->id)->sole()->autorisee);
        $this->assertDatabaseHas('notification', ['utilisateur_id' => $this->gestionnaireFilles->id, 'titre' => 'Absence non autorisée']);

        // Une seconde saisie du même jour ne double rien.
        $ecran->set('absents', [(string) $deux->id])->call('saisir');
        $this->assertSame(2, Absence::count());

        // Une résidente d'un autre internat ne peut pas être glissée dans la feuille.
        $ecran->set('absents', [(string) $etrangere->id])->call('saisir');
        $this->assertSame(0, Absence::where('affectation_id', $etrangere->id)->count());

        // Registre : annulation d'une saisie erronée, tracée au journal.
        $absence = Absence::where('affectation_id', $deux->id)->sole();
        Livewire::actingAs($this->gestionnaireGarcons)->test(Presences::class)->call('annulerAbsence', $absence->id)->assertForbidden();
        Livewire::actingAs($this->gestionnaireFilles)->test(Presences::class)->call('voir', 'absences')->set('nonAutorisees', true)
            ->assertSee('Non autorisée')->call('annulerAbsence', $absence->id)->assertHasNoErrors();
        $this->assertNull($absence->fresh());
        $this->assertTrue(JournalAudit::where('action', 'absence.annulation')->exists());
    }

    public function test_un_incident_se_declare_avec_son_rapport_et_reste_prive(): void
    {
        [$une] = $this->residentes();
        $etrangere = $this->creerResident($this->creerCampagne($this->garcons), $this->creerLits($this->garcons)->first());

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Incidents::class)->assertSee('Aucun incident enregistré')->call('preparerIncident')
            ->assertSee($une->demande->stagiaire->nom)->assertDontSee($etrangere->demande->stagiaire->nom);

        $ecran->call('enregistrerIncident')->assertHasErrors(['incident.affectation_id', 'incident.type', 'incident.description']);

        $ecran->set('incident.affectation_id', (string) $une->id)->set('incident.type', TypeIncident::cases()[0]->value)
            ->set('incident.description', 'Bruit après l\'extinction des feux.')
            ->set('incident.date_incident', now()->addDay()->format('Y-m-d\TH:i'))->call('enregistrerIncident')->assertHasErrors('incident.date_incident');

        // Un rapport qui n'est pas un PDF est refusé par le contrôle des documents.
        $ecran->set('incident.date_incident', now()->subHour()->format('Y-m-d\TH:i'))
            ->set('rapport', UploadedFile::fake()->createWithContent('rapport.txt', 'texte'))->call('enregistrerIncident');
        $this->assertSame(0, Incident::count());

        $ecran->set('rapport', $this->pdf())->call('enregistrerIncident')->assertHasNoErrors()->assertSee('Bruit après');
        $incident = Incident::sole();
        $this->assertSame($une->id, $incident->affectation_id);
        $this->assertSame($this->gestionnaireFilles->id, $incident->saisi_par);
        Storage::disk('prive')->assertExists($incident->rapport_fichier);

        // Une résidente d'un autre internat n'existe pas pour ce compte.
        Livewire::actingAs($this->gestionnaireFilles)->test(Incidents::class)->call('preparerIncident')
            ->set('incident.affectation_id', (string) $etrangere->id)->set('incident.type', TypeIncident::cases()[0]->value)
            ->set('incident.description', 'Tentative hors périmètre.')->call('enregistrerIncident')->assertNotFound();
        $this->assertSame(1, Incident::count());

        // Le rapport ne se lit que dans le périmètre, et sa consultation est tracée.
        $this->connecter($this->gestionnaireFilles)->get(route('vie-internat.incidents.rapport', $incident))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertTrue(JournalAudit::where('action', 'incident.consultation_rapport')->exists());
        $this->connecter($this->gestionnaireGarcons)->get(route('vie-internat.incidents.rapport', $incident))->assertForbidden();
        $this->connecter($this->creerStagiaire($this->filles))->get(route('vie-internat.incidents.rapport', $incident))->assertRedirect();
    }

    public function test_une_maintenance_bloque_la_chambre_jusqu_a_sa_resolution(): void
    {
        $chambre = $this->creerLits($this->filles)->first()->chambre;
        $ailleurs = $this->creerLits($this->garcons)->first()->chambre;

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Incidents::class)->call('voir', 'maintenance')->call('preparerMaintenance');

        $ecran->set('maintenance.chambre_id', (string) $ailleurs->id)->set('maintenance.description', 'Fuite au lavabo')->call('signaler')->assertNotFound();
        $this->assertSame(0, DemandeMaintenance::count());

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Incidents::class)->call('voir', 'maintenance')->call('preparerMaintenance')
            ->call('signaler')->assertHasErrors(['maintenance.chambre_id', 'maintenance.description'])
            ->set('maintenance.chambre_id', (string) $chambre->id)->set('maintenance.description', 'Fuite au lavabo')->set('maintenance.bloque_chambre', true)
            ->call('signaler')->assertHasNoErrors()->assertSee('Fuite au lavabo')->assertSee('Chambre bloquée');

        $demande = DemandeMaintenance::sole();
        $this->assertSame(StatutChambre::EnMaintenance, $chambre->fresh()->statut);

        $ecran->call('prendreEnCharge', $demande->id);
        $this->assertSame(StatutMaintenance::EnCours, $demande->fresh()->statut);

        // La résolution se décrit ; elle rend la chambre à l'affectation.
        $ecran->call('preparerResolution', $demande->id)->call('resoudre')->assertHasErrors('resolution')
            ->set('resolution', 'Joint remplacé.')->call('resoudre')->assertHasNoErrors();
        $this->assertSame(StatutMaintenance::Resolue, $demande->fresh()->statut);
        $this->assertSame($this->gestionnaireFilles->id, $demande->fresh()->resolu_par);
        $this->assertSame(StatutChambre::Disponible, $chambre->fresh()->statut);

        Livewire::actingAs($this->gestionnaireGarcons)->test(Incidents::class)->call('prendreEnCharge', $demande->id)->assertForbidden();
    }

    public function test_le_catalogue_des_equipements_est_propre_a_chaque_internat(): void
    {
        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Equipements::class)->assertSee('Catalogue vide');

        $ecran->call('ajouter')->call('enregistrer')->assertHasErrors('saisie.libelle')
            ->set('saisie.libelle', 'Clé de chambre')->set('saisie.description', 'À rendre au départ')->call('enregistrer')->assertHasNoErrors()->assertSee('Clé de chambre');

        $cle = Equipement::sole();
        $this->assertSame($this->filles->id, $cle->internat_id);
        $this->assertTrue($cle->actif);

        // Même libellé dans le même internat : refusé. Dans un autre internat : permis.
        $ecran->call('ajouter')->set('saisie.libelle', 'Clé de chambre')->call('enregistrer');
        $this->assertSame(1, Equipement::count());
        Livewire::actingAs($this->gestionnaireGarcons)->test(Equipements::class)->assertDontSee('À rendre au départ')
            ->call('ajouter')->set('saisie.libelle', 'Clé de chambre')->call('enregistrer')->assertHasNoErrors();
        $this->assertSame(2, Equipement::count());

        // Retrait du catalogue : l'équipement n'est plus proposé à la remise, sans être effacé.
        Livewire::actingAs($this->gestionnaireFilles)->test(Equipements::class)->call('modifier', $cle->id)->assertSet('saisie.libelle', 'Clé de chambre')->set('saisie.actif', false)->call('enregistrer')->assertHasNoErrors()->assertSee('Retiré');
        $this->assertFalse($cle->fresh()->actif);

        // L'équipement d'un autre internat ne se modifie pas, et l'internat d'un ajout ne se force pas.
        Livewire::actingAs($this->gestionnaireGarcons)->test(Equipements::class)->call('modifier', $cle->id)->assertForbidden();
        Livewire::actingAs($this->gestionnaireGarcons)->test(Equipements::class)->call('ajouter')
            ->set('saisie.internat_id', (string) $this->filles->id)->set('saisie.libelle', 'Badge')->call('enregistrer')->assertForbidden();
        $this->assertSame(0, Equipement::where('libelle', 'Badge')->count());
    }

    public function test_chaque_responsable_traite_les_reclamations_de_son_domaine(): void
    {
        $stagiaire = $this->creerStagiaire($this->filles);
        $creer = fn (DomaineReclamation $domaine, string $texte) => Reclamation::create([
            'stagiaire_id' => $stagiaire->id, 'internat_id' => $this->filles->id, 'domaine' => $domaine,
            'description' => $texte, 'statut' => StatutReclamation::Ouverte, 'date_creation' => now(),
        ]);
        $hebergement = $creer(DomaineReclamation::Hebergement, 'Le chauffage ne fonctionne pas.');
        $repas = $creer(DomaineReclamation::Restauration, 'Le dîner est servi trop tôt.');
        $selection = $creer(DomaineReclamation::Selection, 'Je conteste mon classement.');

        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(Reclamations::class)
            ->assertSee('Le chauffage ne fonctionne pas.')->assertDontSee('Le dîner est servi trop tôt.')->assertDontSee('Je conteste mon classement.');

        $ecran->call('prendreEnCharge', $hebergement->id);
        $this->assertSame(StatutReclamation::EnCours, $hebergement->fresh()->statut);

        // Clore exige une réponse ; le stagiaire en est prévenu.
        $ecran->call('repondre', $hebergement->id)->assertSee('Répondre à la réclamation')->call('clore')->assertHasErrors('reponse')
            ->set('reponse', 'Le technicien passe demain matin.')->call('clore')->assertHasNoErrors()->assertSee('Le technicien passe demain matin.');
        $this->assertSame(StatutReclamation::Traitee, $hebergement->fresh()->statut);
        $this->assertSame($this->gestionnaireFilles->id, $hebergement->fresh()->traite_par);
        $this->assertDatabaseHas('notification', ['stagiaire_id' => $stagiaire->id, 'titre' => 'Réponse à votre réclamation']);

        // Hors domaine ou hors internat : refusé, même en appelant l'action directement.
        Livewire::actingAs($this->gestionnaireFilles)->test(Reclamations::class)->call('prendreEnCharge', $repas->id)->assertForbidden();
        Livewire::actingAs($this->gestionnaireGarcons)->test(Reclamations::class)->assertDontSee('Le chauffage ne fonctionne pas.');
        Livewire::actingAs($this->gestionnaireGarcons)->test(Reclamations::class)->call('repondre', $hebergement->id)->assertForbidden();
        $this->assertSame(StatutReclamation::Ouverte, $repas->fresh()->statut);

        Livewire::actingAs($this->restauration)->test(Reclamations::class)->assertSee('Le dîner est servi trop tôt.')->assertDontSee('Je conteste mon classement.');
        Livewire::actingAs($this->chefDePole)->test(Reclamations::class)->assertSee('Je conteste mon classement.')->assertDontSee('Le dîner est servi trop tôt.')
            ->set('statut', StatutReclamation::Traitee->value)->assertDontSee('Je conteste mon classement.');

        // Un rôle sans domaine n'ouvre pas l'écran.
        $this->connecter($this->directeur)->get('/reclamations')->assertForbidden();
        $this->assertSame(StatutReclamation::Ouverte, $selection->fresh()->statut);
    }

    public function test_un_document_utile_se_publie_pour_les_stagiaires_de_l_internat_seulement(): void
    {
        $ecran = Livewire::actingAs($this->gestionnaireFilles)->test(DocumentsInternat::class)->assertSee('Aucun document publié');

        $ecran->call('preparer')->call('publier')->assertHasErrors(['saisie.titre', 'fichier']);

        // Seul un PDF est accepté.
        $ecran->set('saisie.titre', 'Règlement intérieur 2026')->set('saisie.type', TypeDocument::ReglementInterieur->value)
            ->set('fichier', UploadedFile::fake()->createWithContent('reglement.txt', 'texte'))->call('publier');
        $this->assertSame(0, Document::count());

        $ecran->set('fichier', $this->pdf('reglement.pdf'))->call('publier')->assertHasNoErrors()->assertSee('Règlement intérieur 2026');
        $document = Document::sole();
        $this->assertSame($this->filles->id, $document->internat_id);
        $this->assertSame($this->gestionnaireFilles->id, $document->publie_par);
        Storage::disk('prive')->assertExists($document->fichier);

        // Lecture : celui qui publie, et les stagiaires de cet internat ; personne d'autre.
        $this->connecter($this->gestionnaireFilles)->get(route('documents.fichier', $document))->assertOk();
        $this->connecter($this->gestionnaireGarcons)->get(route('documents.fichier', $document))->assertForbidden();
        $this->connecter($this->creerStagiaire($this->filles))->get(route('stagiaire.documents.fichier', $document))->assertOk();
        $this->connecter($this->creerStagiaire($this->garcons))->get(route('stagiaire.documents.fichier', $document))->assertNotFound();

        // Publier dans un internat hors périmètre est refusé.
        Livewire::actingAs($this->gestionnaireGarcons, 'web')->test(DocumentsInternat::class)->assertDontSee('Règlement intérieur 2026')->call('preparer')
            ->set('saisie.internat_id', (string) $this->filles->id)->set('saisie.titre', 'Intrus')->set('fichier', $this->pdf())->call('publier')->assertForbidden();
        Livewire::actingAs($this->gestionnaireGarcons, 'web')->test(DocumentsInternat::class)->call('retirer', $document->id)->assertForbidden();
        $this->assertSame(1, Document::count());

        // Retrait : la ligne et le fichier disparaissent, la trace reste au journal.
        $chemin = $document->fichier;
        Livewire::actingAs($this->gestionnaireFilles, 'web')->test(DocumentsInternat::class)->call('consulter', $document->id)->assertSet('dialogue', 'apercu')
            ->call('fermerDialogue')->call('retirer', $document->id)->assertHasNoErrors()->assertSee('Aucun document publié');
        $this->assertSame(0, Document::count());
        Storage::disk('prive')->assertMissing($chemin);
        $this->assertTrue(JournalAudit::where('action', 'document.suppression')->exists());
    }
}
