<?php

namespace Tests\Feature;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Payments\SuiviPaiements;
use App\Enums\StatutPaiement;
use App\Enums\TypeNotification;
use App\Livewire\Personnel\Paiements;
use App\Livewire\Stagiaire\Paiements as PaiementsDuStagiaire;
use App\Models\NotificationInterne;
use App\Models\Paiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Frais d'internat mensuels, payés directement par le stagiaire : le montant de la campagne est
 * dû chaque mois de séjour, chaque mois a son paiement (justificatif, vérification), aucune
 * autorisation préalable n'est demandée, et le paiement ne conditionne jamais le séjour.
 */
class PaiementsMensuelsTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_le_lit_s_affecte_et_l_arrivee_s_enregistre_sans_paiement_puis_chaque_mois_est_a_payer(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['frais' => 350]);
        $lit = $this->creerLits($this->filles)->first();
        $suivi = app(SuiviPaiements::class);

        // Place confirmée : aucun paiement à ce stade, et l'occupation est autorisée sans paiement.
        $demande = $this->creerConfirme($campagne);
        $this->assertSame(0, $demande->paiements()->count());
        $this->assertTrue($demande->fresh()->occupationAutorisee());

        // Le lit s'affecte et l'arrivée s'enregistre, sans paiement préalable.
        $gestionnaire = $this->gestionnaireFilles;
        $this->agirEnTantQue($gestionnaire);
        $affectation = app(AffectationLits::class)->affecter($demande->fresh(), $lit, now(), $gestionnaire);
        app(AffectationLits::class)->enregistrerArrivee($affectation, $gestionnaire);
        $this->assertTrue($affectation->fresh()->arriveeEnregistree());

        // Le séjour commence : la mensualité du premier mois est ouverte, à payer aussitôt.
        $premier = $demande->paiements()->sole();
        $this->assertSame([now()->format('Y-m'), '350.00', StatutPaiement::EnAttente, false, null], [$premier->periode, (string) $premier->montant, $premier->statut, $premier->genere_auto, $premier->autorise_par]);
        $this->assertSame('En attente de paiement', $premier->statut->libelle());

        // Le 1er du mois suivant : la mensualité du mois est ouverte, à payer, au montant de la campagne.
        $suivant = now()->addMonthNoOverflow()->startOfMonth();
        $this->assertSame(1, $suivi->genererMensualites($suivant));
        $mois = Paiement::where('demande_id', $demande->id)->where('periode', $suivant->format('Y-m'))->sole();
        $this->assertSame(['350.00', StatutPaiement::EnAttente, true], [(string) $mois->montant, $mois->statut, $mois->genere_auto]);
        $this->assertTrue(NotificationInterne::where('stagiaire_id', $demande->stagiaire_id)->where('type', TypeNotification::RappelPaiement->value)
            ->where('message', 'like', '%'.$suivant->translatedFormat('F Y').'%')->exists());

        // Rejouable ; le mois en cours, déjà ouvert, n'est pas recréé.
        $this->assertSame(0, $suivi->genererMensualites($suivant));
        $this->assertSame(0, $suivi->genererMensualites(now()));
        $this->assertSame(2, $demande->paiements()->count());

        // Le stagiaire dépose directement son justificatif, sans autorisation ; chaque mois suit son parcours.
        $this->agirEnTantQue($demande->stagiaire);
        $suivi->deposerRecu($premier, UploadedFile::fake()->create('justificatif.pdf', 20, 'application/pdf'));
        $this->assertSame([StatutPaiement::RecuDepose, StatutPaiement::EnAttente], [$premier->fresh()->statut, $mois->fresh()->statut]);

        // Le gestionnaire rejette : le stagiaire peut déposer un justificatif corrigé, puis il est validé.
        $this->agirEnTantQue($gestionnaire);
        $suivi->refuserRecu($premier->fresh(), $gestionnaire, 'Montant illisible');
        $this->agirEnTantQue($demande->stagiaire);
        $suivi->deposerRecu($premier->fresh(), UploadedFile::fake()->create('corrige.pdf', 20, 'application/pdf'));
        $this->agirEnTantQue($gestionnaire);
        $suivi->validerRecu($premier->fresh(), $gestionnaire);
        $this->assertSame(StatutPaiement::Valide, $premier->fresh()->statut);
        $this->assertRegle('depot_impossible', fn () => $suivi->deposerRecu($premier->fresh(), UploadedFile::fake()->create('encore.pdf', 20, 'application/pdf')));

        // Un séjour terminé n'ouvre plus de mensualité.
        $affectation->forceFill(['date_fin' => now()])->save();
        $this->assertSame(0, $suivi->genererMensualites($suivant->copy()->addMonthNoOverflow()));
    }

    public function test_le_chef_de_pole_consulte_sans_autoriser_et_le_stagiaire_paie_directement(): void
    {
        $campagne = $this->creerCampagne($this->filles, ['frais' => 350]);
        $demande = $this->creerResident($campagne, $this->creerLits($this->filles)->first())->demande;
        $suivant = now()->addMonthNoOverflow()->startOfMonth();
        app(SuiviPaiements::class)->genererMensualites($suivant);
        $libelle = fn ($mois) => $mois->translatedFormat('F Y');

        // Le chef de pôle consulte et suit : plus aucune autorisation à délivrer.
        $this->assertFalse($this->chefDePole->can('paiements.autoriser'));
        $this->assertFalse($this->chefDePole->can('paiements.controler'));
        $this->ecran($this->chefDePole, Paiements::class)->assertSee($libelle(now()))->assertSee($libelle($suivant))
            ->assertSee('En attente de paiement')->assertSee('350,00')->assertDontSee('Autoriser')
            ->set('periode', $suivant->format('Y-m'))->assertSee($libelle($suivant))->assertDontSee($libelle(now()));
        $this->assertFalse(method_exists(Paiements::class, 'autoriserSelection'));
        $this->assertFalse(method_exists(SuiviPaiements::class, 'autoriser'));

        // Le stagiaire voit chaque mensualité (période, montant, état) et peut déposer son justificatif tout de suite.
        $this->ecran($demande->stagiaire, PaiementsDuStagiaire::class)
            ->assertSee('Frais d’internat — '.$libelle(now()), false)->assertSee('Frais d’internat — '.$libelle($suivant), false)
            ->assertSee('En attente de paiement')->assertSee('Déposer mon justificatif de paiement')
            ->assertDontSee('autorisation du chef de pôle');
        $this->connecter($demande->stagiaire)->get(route('stagiaire.paiements.autorisation', $demande->paiements()->first()))->assertOk();

        // La campagne affiche des frais mensuels.
        $this->connecter($this->chefDePole)->get(route('campagnes.fiche', $campagne))->assertSee('par mois');
    }
}
