<?php

namespace Tests\Feature;

use App\Domain\Applications\DossierStagiaire;
use App\Livewire\Stagiaire\Dossier as EcranDossier;
use App\Livewire\Stagiaire\Profil;
use App\Models\Stagiaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Cloisonnement entre stagiaires : l'espace du stagiaire ne prend jamais
 * d'identifiant de stagiaire, chacun n'y voit que ce qui est à lui.
 *
 * L'administration des comptes (suspension, rétablissement, lien de
 * réinitialisation) est couverte par EcransAdministrationTest ; la création du
 * compte par le stagiaire, par InscriptionStagiaireTest.
 */
class ComptesStagiairesTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    public function test_un_stagiaire_n_accede_jamais_au_dossier_d_un_autre(): void
    {
        $campagne = $this->creerCampagne($this->filles);
        $proprietaire = $this->creerStagiaire($this->filles);
        $curieuse = $this->creerStagiaire($this->filles);
        $dossier = $this->creerDossierRecu($campagne, stagiaire: $proprietaire);
        $piece = $dossier->pieces()->firstOrFail();

        // Sans dossier à elle, son écran est vide ; son profil est le sien.
        $this->ecran($curieuse, EcranDossier::class)->assertDontSee($dossier->numero_dossier)->assertDontSee($proprietaire->cef);
        $this->ecran($curieuse, Profil::class)->assertSee($curieuse->email)->assertDontSee($proprietaire->email);
        $this->connecter($curieuse)->get(route('stagiaire.dossier.piece', $piece))->assertNotFound();
        $this->ecran($curieuse, EcranDossier::class)->call('retirerPiece', $piece->id)->assertNotFound();
        $this->ecran($curieuse, EcranDossier::class)->call('voirPiece', $piece->id)->assertNotFound();

        // Même avec un dossier à elle, la pièce d'une autre reste introuvable.
        $this->creerDossierRecu($campagne, stagiaire: $curieuse);
        $this->connecter($curieuse)->get(route('stagiaire.dossier.piece', $piece))->assertNotFound();
        $this->ecran($curieuse, EcranDossier::class)->call('retirerPiece', $piece->id)->assertNotFound();
        $this->assertNotNull($piece->fresh());

        // Le domaine refuse lui aussi de retirer la pièce d'un autre dossier.
        $this->agirEnTantQue($curieuse);
        try {
            app(DossierStagiaire::class)->retirerPiece($curieuse->demandeCourante(), $piece);
            $this->fail('La pièce d\'un autre dossier ne se retire pas.');
        } catch (\Throwable) {
            $this->assertNotNull($piece->fresh());
        }

        // Les écrans du personnel lui sont fermés, même en devinant les identifiants.
        foreach ([route('dossiers.fiche', $dossier), route('dossiers.piece', $piece), '/dossiers'] as $adresse) {
            $this->assertContains($this->connecter($curieuse)->get($adresse)->status(), [302, 403]);
        }

        $this->assertSame($proprietaire->id, $dossier->fresh()->stagiaire_id);
        $this->assertNotNull(Stagiaire::find($proprietaire->id));
    }
}
