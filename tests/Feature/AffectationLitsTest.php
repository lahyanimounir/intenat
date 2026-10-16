<?php

namespace Tests\Feature;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Accommodation\Referentiel;
use App\Domain\Residence\VieInternat;
use App\Enums\EtatRestitution;
use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutDemande;
use App\Enums\StatutLit;
use App\Enums\StatutMaintenance;
use App\Enums\TypeChambre;
use App\Enums\TypeMouvement;
use App\Livewire\Personnel\Affectations;
use App\Livewire\Personnel\Incidents;
use App\Livewire\Personnel\Plan;
use App\Livewire\Personnel\ResidentFiche;
use App\Models\Affectation;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\DemandeMaintenance;
use App\Models\Equipement;
use App\Models\Lit;
use App\Models\Mouvement;
use App\Models\NotificationInterne;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Affectation des lits : règles de placement, cloisonnement par internat,
 * historique des périodes et garde-fou d'unicité de la base.
 */
class AffectationLitsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    private Campagne $campagne;

    private Collection $lits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
        $this->campagne = $this->creerCampagne($this->filles, ['nombre_places' => 10]);
        $this->lits = $this->creerLits($this->filles, 2, 2);
    }

    /** Attribution d'un lit par le gestionnaire des filles, telle que le domaine l'applique. */
    private function affecter(Demande $demande, Lit $lit, ?string $motif = null): Affectation
    {
        $this->agirEnTantQue($this->gestionnaireFilles);

        return app(AffectationLits::class)->affecter($demande->fresh(), $lit->fresh(), now(), $this->gestionnaireFilles, $motif);
    }

    public function test_le_gestionnaire_affecte_un_lit_a_un_admis_confirme(): void
    {
        $demande = $this->creerConfirme($this->campagne);
        $lit = $this->lits[0];

        $this->ecran($this->gestionnaireFilles, Affectations::class)->call('preparer', $demande->id)
            ->set('lit', (string) $lit->id)->call('affecter')->assertHasNoErrors();

        $this->assertSame(StatutLit::Occupe, $lit->fresh()->statut);
        $this->assertDatabaseHas('affectation', ['demande_id' => $demande->id, 'lit_id' => $lit->id, 'date_fin' => null, 'affecte_par' => $this->gestionnaireFilles->id]);
        $this->assertDatabaseHas('journal_audit', ['action' => 'hebergement.affectation', 'utilisateur_id' => $this->gestionnaireFilles->id]);
        $this->assertDatabaseHas('notification', ['stagiaire_id' => $demande->stagiaire_id, 'titre' => 'Chambre attribuée']);

        // La chambre passe à « Occupée » quand tous ses lits utilisables sont pris.
        $this->assertSame(StatutChambre::Disponible, $lit->chambre->fresh()->statut);
        $this->affecter($this->creerConfirme($this->campagne), $this->lits[1]);
        $this->assertSame(StatutChambre::Occupee, $lit->chambre->fresh()->statut);
    }

    public function test_un_lit_ne_s_attribue_qu_a_un_candidat_autorise(): void
    {
        $lit = $this->lits[0];

        // Dossier complet mais non admis, puis admis non confirmé.
        $complet = $this->rendreComplet($this->creerDossierRecu($this->campagne));
        $this->assertRegle('candidat_non_autorise', fn () => $this->affecter($complet, $lit));

        $admis = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $this->campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id,
        ]);
        $this->assertRegle('candidat_non_autorise', fn () => $this->affecter($admis, $lit));

        // Confirmé, mais occupation pas encore autorisée (autorisation de paiement non délivrée).
        $admis->forceFill(['statut' => StatutDemande::Confirme])->save();
        $this->assertRegle('occupation_non_autorisee', fn () => $this->affecter($admis, $lit));
        $this->assertRegle('occupation_non_autorisable', fn () => app(AffectationLits::class)->autoriserOccupation($admis->fresh(), $this->gestionnaireFilles));

        // À l'écran, la même règle s'applique et s'explique.
        $this->ecran($this->gestionnaireFilles, Affectations::class)->call('autoriserOccupation', $admis->id);
        $this->assertFalse($admis->fresh()->occupationAutorisee());

        $this->assertSame(0, Affectation::count());
        $this->assertSame(StatutLit::Disponible, $lit->fresh()->statut);
    }

    public function test_un_lit_hors_service_en_maintenance_ou_dans_un_batiment_ferme_est_refuse(): void
    {
        $demande = $this->creerConfirme($this->campagne);

        $this->lits[0]->forceFill(['statut' => StatutLit::HorsService])->save();
        $this->assertRegle('lit_hors_service', fn () => $this->affecter($demande, $this->lits[0]));

        $this->lits[2]->chambre->forceFill(['statut' => StatutChambre::EnMaintenance])->save();
        $this->assertRegle('chambre_en_maintenance', fn () => $this->affecter($demande, $this->lits[2]));

        $this->lits[1]->chambre->batiment->forceFill(['statut' => StatutBatiment::EnTravaux])->save();
        $this->assertRegle('batiment_ferme', fn () => $this->affecter($demande, $this->lits[1]));

        $this->assertSame(0, Affectation::count());
    }

    public function test_les_chambres_isolement_et_infirmerie_exigent_un_motif(): void
    {
        $demande = $this->creerConfirme($this->campagne);
        $this->lits[0]->chambre->forceFill(['type_chambre' => TypeChambre::Isolement])->save();

        $this->assertRegle('motif_requis', fn () => $this->affecter($demande, $this->lits[0]));

        $this->ecran($this->gestionnaireFilles, Affectations::class)->call('preparer', $demande->id)
            ->set('lit', (string) $this->lits[0]->id)->set('motif', 'Isolement sanitaire temporaire.')->call('affecter')->assertHasNoErrors();
        $this->assertSame('Isolement sanitaire temporaire.', Affectation::sole()->motif_exceptionnel);
    }

    public function test_un_lit_d_un_autre_internat_ne_peut_pas_etre_attribue(): void
    {
        $demande = $this->creerConfirme($this->campagne);
        $litGarcons = $this->creerLits($this->garcons, 1, 1)[0];

        // Le gestionnaire filles ne « voit » pas le lit des garçons : introuvable pour lui.
        $this->ecran($this->gestionnaireFilles, Affectations::class)->call('preparer', $demande->id)
            ->set('lit', (string) $litGarcons->id)->call('affecter')->assertNotFound();

        // Le gestionnaire garçons ne voit pas le dossier des filles.
        $this->ecran($this->gestionnaireGarcons, Affectations::class)->call('preparer', $demande->id)->assertNotFound();

        // Même en appelant le service directement, le croisement d'internats est refusé.
        $this->assertRegle('lit_autre_internat', fn () => app(AffectationLits::class)->affecter($demande, $litGarcons, now(), $this->gestionnaireGarcons));

        $this->assertSame(0, Affectation::count());
        $this->assertSame(StatutLit::Disponible, $litGarcons->fresh()->statut);
    }

    public function test_ni_double_occupation_d_un_lit_ni_deux_lits_pour_un_meme_dossier(): void
    {
        $premier = $this->creerConfirme($this->campagne);
        $second = $this->creerConfirme($this->campagne);

        $this->affecter($premier, $this->lits[0]);
        $this->assertSame(409, $this->assertRegle('lit_occupe', fn () => $this->affecter($second, $this->lits[0]))->statutHttp);
        $this->assertSame(409, $this->assertRegle('deja_affecte', fn () => $this->affecter($premier, $this->lits[1]))->statutHttp);

        $this->assertSame(1, Affectation::count());
    }

    public function test_la_base_refuse_deux_affectations_actives_meme_sans_passer_par_l_application(): void
    {
        $premier = $this->creerConfirme($this->campagne);
        $second = $this->creerConfirme($this->campagne);
        $base = ['date_debut' => now()->toDateString(), 'affecte_par' => $this->gestionnaireFilles->id];

        Affectation::create($base + ['demande_id' => $premier->id, 'lit_id' => $this->lits[0]->id]);

        try {
            Affectation::create($base + ['demande_id' => $second->id, 'lit_id' => $this->lits[0]->id]);
            $this->fail('La base aurait dû refuser un second occupant actif.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('affectation_lit_actif_unique', $e->getMessage());
        }

        try {
            Affectation::create($base + ['demande_id' => $premier->id, 'lit_id' => $this->lits[1]->id]);
            $this->fail('La base aurait dû refuser un second lit actif pour le même dossier.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertStringContainsString('affectation_demande_active_unique', $e->getMessage());
        }

        // Une période close ne bloque plus : l'historique peut contenir plusieurs occupants successifs.
        Affectation::first()->forceFill(['date_fin' => now()->toDateString()])->save();
        Affectation::create($base + ['demande_id' => $second->id, 'lit_id' => $this->lits[0]->id]);
        $this->assertSame(2, Affectation::where('lit_id', $this->lits[0]->id)->count());
    }

    public function test_la_capacite_de_la_chambre_ne_peut_pas_etre_depassee(): void
    {
        $chambre = $this->lits[0]->chambre;
        $chambre->forceFill(['capacite' => 1])->save();
        $this->creerResident($this->campagne, $this->lits[0]);

        $this->assertRegle('capacite_atteinte', fn () => $this->affecter($this->creerConfirme($this->campagne), $this->lits[1]));
    }

    public function test_un_changement_de_lit_conserve_la_periode_precedente(): void
    {
        $affectation = $this->creerResident($this->campagne, $this->lits[0]);
        $literie = Equipement::create(['internat_id' => $this->filles->id, 'libelle' => 'Literie', 'actif' => true]);
        $this->agirEnTantQue($this->gestionnaireFilles);
        app(VieInternat::class)->remettreEquipement($affectation, $literie, $this->gestionnaireFilles);

        // Sans motif, le changement n'est pas enregistré.
        $fiche = $this->ecran($this->gestionnaireFilles, ResidentFiche::class, ['affectation' => $affectation]);
        $fiche->call('preparer', 'lit')->set('lit', (string) $this->lits[2]->id)->call('changerDeLit')->assertHasErrors('motif');
        $this->assertSame(1, Affectation::count());

        $fiche->set('motif', 'Rapprochement d\'une camarade de filière.')->call('changerDeLit')->assertHasNoErrors();

        // L'ancienne période est close, pas écrasée ; la nouvelle est active.
        $ancienne = $affectation->fresh();
        $nouvelle = Affectation::where('demande_id', $affectation->demande_id)->whereNull('date_fin')->sole();
        $this->assertNotNull($ancienne->date_fin);
        $this->assertSame($this->lits[0]->id, $ancienne->lit_id);
        $this->assertSame($this->lits[2]->id, $nouvelle->lit_id);
        $this->assertSame(2, Affectation::where('demande_id', $affectation->demande_id)->count());

        $this->assertSame(StatutLit::Disponible, $this->lits[0]->fresh()->statut);
        $this->assertSame(StatutLit::Occupe, $this->lits[2]->fresh()->statut);

        // Mouvement tracé, arrivée reportée, équipements suivis sur le nouveau séjour.
        $this->assertTrue(Mouvement::where('affectation_id', $nouvelle->id)->where('type', TypeMouvement::ChangementChambre->value)->exists());
        $this->assertTrue($nouvelle->arriveeEnregistree());
        $this->assertSame(1, $nouvelle->attributions()->whereNull('date_restitution')->count());

        // L'historique du lit libéré garde son ancien occupant.
        $this->assertSame([$ancienne->id], Affectation::where('lit_id', $this->lits[0]->id)->pluck('id')->all());
        $this->ecran($this->gestionnaireFilles, Plan::class)->call('voirLit', $this->lits[0]->id)->assertSee($ancienne->demande->stagiaire->nom);
    }

    public function test_la_fin_de_sejour_libere_le_lit_garde_l_historique_et_alerte_le_chef_de_pole(): void
    {
        $affectation = $this->creerResident($this->campagne, $this->lits[0]);
        $enAttente = Demande::factory()->deposee(StatutDemande::EnAttente)->create([
            'campagne_id' => $this->campagne->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id, 'rang' => 2,
        ]);
        $this->campagne->forceFill(['date_deliberation' => now()->subDay(), 'nombre_places' => 1])->save();

        $cle = Equipement::create(['internat_id' => $this->filles->id, 'libelle' => 'Clé', 'actif' => true]);
        $this->agirEnTantQue($this->gestionnaireFilles);
        $attribution = app(VieInternat::class)->remettreEquipement($affectation, $cle, $this->gestionnaireFilles);

        $fin = fn (TypeMouvement $type, string $motif) => fn () => app(AffectationLits::class)->cloturer($affectation->fresh(), $type, $motif, $this->gestionnaireFilles);

        // Déjà arrivé : « non présenté » est incohérent ; équipement non rendu : départ bloqué.
        $this->assertRegle('deja_arrive', $fin(TypeMouvement::NonPresente, 'Jamais venue'));
        $this->assertRegle('equipements_non_restitues', $fin(TypeMouvement::Depart, 'Fin de formation'));

        app(VieInternat::class)->restituerEquipement($attribution, EtatRestitution::Bon, null, $this->gestionnaireFilles);
        $this->ecran($this->gestionnaireFilles, ResidentFiche::class, ['affectation' => $affectation])->call('preparer', 'fin')
            ->set('type', 'depart')->set('motif', 'Fin de formation')->call('cloturer')->assertHasNoErrors();

        // Lit libéré, période conservée, mouvement et statut du dossier cohérents.
        $this->assertSame(StatutLit::Disponible, $this->lits[0]->fresh()->statut);
        $this->assertNotNull($affectation->fresh()->date_fin);
        $this->assertSame(StatutDemande::SejourTermine, $affectation->demande->fresh()->statut);
        $this->assertTrue(Mouvement::where('affectation_id', $affectation->id)->where('type', 'depart')->exists());

        // Place libérée : le chef de pôle est alerté, le candidat en attente n'est pas promu d'office.
        $this->assertSame(1, NotificationInterne::where('utilisateur_id', $this->chefDePole->id)->where('type', 'place_libre')->count());
        $this->assertSame(StatutDemande::EnAttente, $enAttente->fresh()->statut);

        // Un séjour clos ne se clôt pas deux fois.
        $this->agirEnTantQue($this->gestionnaireFilles);
        $this->assertSame(409, $this->assertRegle('sejour_clos', $fin(TypeMouvement::Depart, 'Second appel'))->statutHttp);
    }

    public function test_un_lit_occupe_ne_peut_pas_etre_mis_hors_service(): void
    {
        $this->creerResident($this->campagne, $this->lits[0]);
        $this->agirEnTantQue($this->gestionnaireFilles);

        $this->assertRegle('lit_occupe', fn () => app(Referentiel::class)->basculerLit($this->lits[0]->fresh(), true));

        $this->ecran($this->gestionnaireFilles, Plan::class)->call('voirLit', $this->lits[1]->id)->call('basculerLit', true)->assertHasNoErrors();
        $this->assertSame(StatutLit::HorsService, $this->lits[1]->fresh()->statut);

        // Le seul lit encore utilisable de la chambre est occupé : la chambre est « Occupée ».
        $this->assertSame(StatutChambre::Occupee, $this->lits[0]->chambre->fresh()->statut);
    }

    public function test_la_maintenance_sort_la_chambre_de_l_affectation_puis_la_remet_en_service(): void
    {
        $chambre = $this->lits[2]->chambre;

        $ecran = $this->ecran($this->gestionnaireFilles, Incidents::class)->call('preparerMaintenance')
            ->set('maintenance.chambre_id', (string) $chambre->id)->set('maintenance.description', 'Fuite au plafond')->set('maintenance.bloque_chambre', true)
            ->call('signaler')->assertHasNoErrors();
        $demande = DemandeMaintenance::sole();

        $this->assertSame(StatutChambre::EnMaintenance, $chambre->fresh()->statut);
        $this->assertRegle('chambre_en_maintenance', fn () => $this->affecter($this->creerConfirme($this->campagne), $this->lits[2]));

        // La résolution se décrit, puis la chambre redevient attribuable.
        $ecran = $this->ecran($this->gestionnaireFilles, Incidents::class);
        $ecran->call('preparerResolution', $demande->id)->call('resoudre')->assertHasErrors('resolution');
        $this->assertSame(StatutChambre::EnMaintenance, $chambre->fresh()->statut);
        $ecran->set('resolution', 'Étanchéité refaite.')->call('resoudre')->assertHasNoErrors();
        $this->assertSame([StatutMaintenance::Resolue, StatutChambre::Disponible], [$demande->fresh()->statut, $chambre->fresh()->statut]);

        // Le gestionnaire de l'autre internat ne peut pas signaler dans cette chambre.
        $this->ecran($this->gestionnaireGarcons, Incidents::class)->call('preparerMaintenance')
            ->set('maintenance.chambre_id', (string) $chambre->id)->set('maintenance.description', 'Intrusion')->set('maintenance.bloque_chambre', true)
            ->call('signaler')->assertNotFound();
        $this->assertSame(1, DemandeMaintenance::count());
    }
}
