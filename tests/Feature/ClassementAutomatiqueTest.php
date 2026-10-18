<?php

namespace Tests\Feature;

use App\Enums\ListeAdmission;
use App\Enums\StatutCampagne;
use App\Jobs\RecalculerClassement;
use App\Livewire\Personnel\Classement;
use App\Models\Demande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Le classement suit la validation des dossiers sans intervention : dès que le gestionnaire
 * déclare un dossier complet, le score est calculé et le candidat est classé. Rien n'attend
 * une file d'attente ni un clic sur « Recalculer le classement ».
 */
class ClassementAutomatiqueTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_la_validation_du_gestionnaire_classe_aussitot_le_candidat(): void
    {
        // Aucune tâche de file d'attente ne s'exécute : le classement ne doit pas en dépendre.
        Queue::fake();
        $campagne = $this->creerCampagne($this->filles, ['nombre_places' => 1]);

        $premier = $this->rendreComplet($this->creerDossierRecu($campagne, ['demuni' => true]));
        $second = $this->rendreComplet($this->creerDossierRecu($campagne));

        Queue::assertNotPushed(RecalculerClassement::class);
        $this->assertSame([1, ListeAdmission::Principale], [$premier->fresh()->rang, $premier->fresh()->liste]);
        $this->assertSame([2, ListeAdmission::Attente], [$second->fresh()->rang, $second->fresh()->liste]);
        $this->assertNotNull($premier->fresh()->score);

        // Campagne clôturée : le chef de pôle voit le classement et peut imprimer la liste, sans recalcul manuel.
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();
        $this->ecran($this->chefDePole, Classement::class)->set('campagne', (string) $campagne->id)
            ->assertDontSee('Aucun dossier classé')->assertSee($premier->numero_dossier)->assertSee($second->numero_dossier);
        $this->connecter($this->chefDePole)->get(route('admissions.classement.impression', $campagne))
            ->assertOk()->assertSee($premier->numero_dossier)->assertSee($second->numero_dossier);
    }

    public function test_un_classement_reste_en_attente_est_rattrape_a_l_affichage(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $dossier = $this->rendreComplet($this->creerDossierRecu($campagne));

        // Situation laissée par l'ancien fonctionnement : dossier complet, recalcul jamais exécuté.
        Demande::whereKey($dossier->id)->update(['rang' => null, 'liste' => null]);

        $this->ecran($this->chefDePole, Classement::class)->set('campagne', (string) $campagne->id)
            ->assertDontSee('Aucun dossier classé')->assertSee($dossier->numero_dossier);
        $this->assertSame(1, $dossier->fresh()->rang);
    }
}
