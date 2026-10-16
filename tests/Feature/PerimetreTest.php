<?php

namespace Tests\Feature;

use App\Domain\Identity\Perimetre;
use App\Domain\Reporting\Exports;
use App\Domain\Reporting\TableauxDeBord;
use App\Enums\StatutCampagne;
use App\Enums\StatutExport;
use App\Livewire\Personnel\CampagneFiche;
use App\Livewire\Personnel\Campagnes;
use App\Livewire\Personnel\Classement;
use App\Livewire\Personnel\Dossiers;
use App\Livewire\Personnel\Plan;
use App\Livewire\Personnel\Rapports;
use App\Models\Batiment;
use App\Models\Export;
use App\Models\Lit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Cloisonnement des données : chaque compte ne lit et ne modifie que les
 * lignes de l'internat, de l'établissement ou de la région dont il a la charge.
 */
class PerimetreTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_le_perimetre_de_chaque_role_est_calcule_selon_son_niveau(): void
    {
        $perimetre = app(Perimetre::class);

        $this->assertNull($perimetre->internatIds($this->admin));
        $this->assertNull($perimetre->internatIds($this->directionCentrale));
        $this->assertSame([$this->filles->id], $perimetre->internatIds($this->gestionnaireFilles));
        $this->assertEqualsCanonicalizing([$this->filles->id, $this->garcons->id], $perimetre->internatIds($this->chefDePole));
        $this->assertEqualsCanonicalizing([$this->filles->id, $this->garcons->id], $perimetre->internatIds($this->directeur));
        $this->assertEqualsCanonicalizing([$this->filles->id, $this->garcons->id], $perimetre->internatIds($this->directionRegionale));

        // Un compte sans périmètre renseigné, ou désactivé, n'a accès à aucune ligne.
        $this->gestionnaireFilles->forceFill(['internat_id' => null])->save();
        $this->chefDePole->forceFill(['actif' => false])->save();
        $perimetre->oublier();

        $this->assertSame([], $perimetre->internatIds($this->gestionnaireFilles));
        $this->assertSame([], $perimetre->internatIds($this->chefDePole));
    }

    public function test_le_gestionnaire_ne_voit_que_les_dossiers_de_son_internat(): void
    {
        $sien = $this->creerDossierRecu($this->creerCampagne($this->filles));
        $autre = $this->creerDossierRecu($this->creerCampagne($this->garcons));

        $this->ecran($this->gestionnaireFilles, Dossiers::class)->assertSee($sien->numero_dossier)->assertDontSee($autre->numero_dossier);

        $this->connecter($this->gestionnaireFilles)->get(route('dossiers.fiche', $sien))->assertOk();
        $this->get(route('dossiers.fiche', $autre))->assertForbidden();

        // Filtrer sur l'autre internat ou sur sa campagne ne contourne pas le périmètre.
        $this->ecran($this->gestionnaireFilles, Dossiers::class)->set('internat', (string) $this->garcons->id)
            ->assertDontSee($autre->numero_dossier)->assertDontSee($sien->numero_dossier);
        $this->ecran($this->gestionnaireFilles, Dossiers::class)->set('campagne', (string) $autre->campagne_id)
            ->assertDontSee($autre->numero_dossier)->assertDontSee($sien->numero_dossier);
    }

    public function test_le_gestionnaire_ne_peut_ni_lire_ni_modifier_le_referentiel_d_un_autre_internat(): void
    {
        $siens = $this->creerLits($this->filles);
        $autres = $this->creerLits($this->garcons);

        $this->ecran($this->gestionnaireFilles, Plan::class)->assertSee('Chambre '.$siens[0]->chambre->numero)->assertDontSee('Chambre '.$autres[0]->chambre->numero);
        $this->ecran($this->gestionnaireFilles, Plan::class)->set('internat', (string) $this->garcons->id)->assertForbidden();
        $this->assertEqualsCanonicalizing($siens->pluck('id')->all(), Lit::visiblesPar($this->gestionnaireFilles)->pluck('lit.id')->all());

        // Statut d'un lit, ajout d'un lit, modification d'un bâtiment : tout est refusé chez le voisin.
        $this->ecran($this->gestionnaireFilles, Plan::class)->call('voirLit', $autres[0]->id)->assertForbidden();
        $this->ecran($this->gestionnaireFilles, Plan::class)->call('preparerLit', $autres[0]->chambre_id)->assertForbidden();
        $this->ecran($this->gestionnaireFilles, Plan::class)->call('preparerBatiment', $autres[0]->chambre->batiment_id)->assertForbidden();

        // Un bâtiment créé depuis cet écran l'est toujours dans son propre internat.
        $this->ecran($this->gestionnaireFilles, Plan::class)->call('preparerBatiment')
            ->set('batiment.code', 'Z')->set('batiment.nom', 'Bloc Z')->set('batiment.nombre_etages', 1)->call('enregistrerBatiment')->assertHasNoErrors();
        $this->assertSame($this->filles->id, Batiment::where('code', 'Z')->sole()->internat_id);
        $this->assertSame(0, Batiment::where('internat_id', $this->garcons->id)->where('code', 'Z')->count());

        $this->assertSame('disponible', $autres[0]->fresh()->statut->value);
    }

    public function test_le_chef_de_pole_couvre_les_deux_internats_de_son_etablissement_et_pas_au_dela(): void
    {
        $filles = $this->creerCampagne($this->filles);
        $garcons = $this->creerCampagne($this->garcons);
        $ailleurs = $this->creerCampagne($this->ailleurs);

        $this->ecran($this->chefDePole, Campagnes::class)->assertSee($this->filles->nom)->assertSee($this->garcons->nom)->assertDontSee($this->ailleurs->nom);

        $this->connecter($this->chefDePole)->get(route('campagnes.fiche', $filles))->assertOk();
        $this->get(route('campagnes.fiche', $garcons))->assertOk();
        $this->get(route('campagnes.fiche', $ailleurs))->assertForbidden();

        $this->ecran($this->chefDePole, CampagneFiche::class, ['campagne' => $ailleurs])->assertForbidden();
        $this->ecran($this->chefDePole, Classement::class)->set('campagne', (string) $ailleurs->id)->call('recalculer')->assertNotFound();
        $this->assertSame(StatutCampagne::Ouverte, $ailleurs->fresh()->statut);
    }

    public function test_le_tableau_de_bord_du_gestionnaire_ne_compte_que_son_internat(): void
    {
        $this->creerLits($this->filles, 2, 2);
        $this->creerLits($this->garcons, 5, 2);
        $this->creerDossierRecu($this->creerCampagne($this->filles));
        $garcons = $this->creerCampagne($this->garcons);
        $this->creerDossierRecu($garcons);
        $this->creerDossierRecu($garcons);

        $tableaux = app(TableauxDeBord::class);

        $filles = $tableaux->pour($this->gestionnaireFilles);
        $this->assertSame(['gestionnaire', $this->filles->id, 1, 4], [$filles['type'], $filles['internat']['id'], $filles['instruction']['a_instruire'], $filles['hebergement']['lits']]);

        app(Perimetre::class)->oublier();
        $autres = $tableaux->pour($this->gestionnaireGarcons);
        $this->assertSame([2, 10], [$autres['instruction']['a_instruire'], $autres['hebergement']['lits']]);
    }

    public function test_une_action_sans_la_permission_du_role_est_refusee_meme_dans_le_perimetre(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $dossier = $this->creerDossierRecu($campagne);

        // Le directeur consulte son tableau de bord, pas les dossiers ni les listes.
        $this->connecter($this->directeur)->get('/')->assertOk()->assertSee('Tableau de bord du complexe');
        $this->get('/dossiers')->assertForbidden();
        $this->get('/admissions/classement')->assertForbidden();

        // Le responsable restauration ne touche pas aux dossiers ; le gestionnaire ne gère pas les comptes.
        $this->connecter($this->restauration)->get(route('dossiers.fiche', $dossier))->assertForbidden();
        $this->connecter($this->gestionnaireFilles)->get('/administration/utilisateurs')->assertForbidden();
        $this->get('/administration/audit')->assertForbidden();

        // L'administrateur gère les comptes mais n'instruit pas et ne délibère pas.
        $this->connecter($this->admin)->get('/administration/utilisateurs')->assertOk();
        $this->get('/dossiers')->assertForbidden();
        $this->ecran($this->admin, Classement::class)->assertForbidden();
        $this->assertNull($campagne->fresh()->date_deliberation);
    }

    public function test_un_export_ne_contient_que_les_lignes_du_perimetre_du_demandeur(): void
    {
        $dossierFille = $this->creerDossierRecu($this->creerCampagne($this->filles));
        $dossierGarcon = $this->creerDossierRecu($this->creerCampagne($this->garcons));

        // La file d'attente des tests est synchrone : l'export est produit à la demande.
        $this->ecran($this->gestionnaireFilles, Rapports::class)->set('type', 'dossiers')->set('format', 'csv')->call('demander')->assertHasNoErrors();

        $export = Export::firstOrFail();
        $this->assertSame(StatutExport::Termine, $export->statut);
        $this->assertSame(1, $export->lignes);

        $contenu = Storage::disk('prive')->get($export->fichier);
        $this->assertStringContainsString($dossierFille->numero_dossier, $contenu);
        $this->assertStringNotContainsString($dossierGarcon->numero_dossier, $contenu);

        // Viser la campagne de l'autre internat : elle n'existe pas pour ce compte, à l'écran comme dans le domaine.
        $this->ecran($this->gestionnaireFilles, Rapports::class)->set('type', 'dossiers')->set('campagne', (string) $dossierGarcon->campagne_id)->call('demander')->assertNotFound();
        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertSame(0, app(Exports::class)->demander($this->gestionnaireFilles, 'dossiers', 'csv', ['campagne_id' => $dossierGarcon->campagne_id])->fresh()->lignes);

        // Un type d'export sans la permission correspondante est refusé.
        $refus = $this->assertRegle('export_interdit', fn () => app(Exports::class)->demander($this->gestionnaireFilles, 'liste_admis', 'csv', ['campagne_id' => $dossierFille->campagne_id]));
        $this->assertSame(403, $refus->statutHttp);

        // Un export n'est téléchargeable que par son auteur.
        $this->connecter($this->gestionnaireFilles)->get(route('rapports.fichier', $export))->assertOk();
        $this->connecter($this->chefDePole)->get(route('rapports.fichier', $export))->assertNotFound();
    }

    public function test_les_types_d_export_proposes_dependent_des_permissions(): void
    {
        $types = fn ($compte) => array_column(app(Exports::class)->typesPour($compte), 'type');

        $this->assertContains('liste_admis', $types($this->chefDePole));
        $this->assertNotContains('liste_admis', $types($this->gestionnaireFilles));
        $this->assertNotContains('dossiers', $types($this->directeur));
        $this->assertSame(['effectifs_repas'], $types($this->restauration));
    }

    public function test_la_direction_regionale_voit_sa_region_et_la_direction_centrale_tout(): void
    {
        $this->creerLits($this->filles, 1, 2);
        $this->creerLits($this->ailleurs, 3, 2);
        $tableaux = app(TableauxDeBord::class);

        $regional = $tableaux->pour($this->directionRegionale);
        $this->assertSame([$this->etablissement->id], array_column($regional['etablissements'], 'id'));
        $this->assertSame(2, $regional['etablissements'][0]['lits']);

        app(Perimetre::class)->oublier();
        $this->assertSame(8, $tableaux->pour($this->directionCentrale)['national']['lits']);
    }
}
