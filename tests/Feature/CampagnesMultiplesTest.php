<?php

namespace Tests\Feature;

use App\Domain\Applications\DossierStagiaire;
use App\Domain\Campaigns\GestionCampagne;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Livewire\Personnel\Campagnes;
use App\Models\Campagne;
use App\Models\Demande;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MondeInternat;
use Tests\TestCase;

/**
 * Plusieurs campagnes d'inscription pour un même internat et une même année scolaire :
 * périodes de dépôt distinctes, numéros de dossier qui ne se répètent pas, et une seule
 * candidature en cours par stagiaire et par année.
 */
class CampagnesMultiplesTest extends TestCase
{
    use MondeInternat, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushHeaders();
        $this->creerMonde();
    }

    /** @return array<string, mixed> */
    private function campagne(string $ouverture, string $cloture): array
    {
        return [
            'annee' => '2026-2027', 'date_ouverture' => $ouverture, 'date_cloture' => $cloture, 'frais' => 100,
            'internats' => [['internat_id' => $this->garcons->id, 'nombre_places' => 2]],
        ];
    }

    public function test_un_internat_ouvre_plusieurs_campagnes_la_meme_annee_sur_des_periodes_distinctes(): void
    {
        $this->agirEnTantQue($this->chefDePole);
        $gestion = app(GestionCampagne::class);

        // Trois campagnes 2026-2027 pour le même internat, l'une après l'autre.
        $gestion->creer($this->chefDePole, $this->campagne('2026-09-01', '2026-09-15'));
        $gestion->creer($this->chefDePole, $this->campagne('2026-10-01', '2026-10-15'));
        [$troisieme] = $gestion->creer($this->chefDePole, $this->campagne('2026-11-01', '2026-11-15'));
        $this->assertSame(3, Campagne::where('internat_id', $this->garcons->id)->where('annee', '2026-2027')->count());

        // Une période qui recouvre une autre, même d'un seul jour, est refusée.
        $refus = $this->assertRegle('periode_chevauchante', fn () => $gestion->creer($this->chefDePole, $this->campagne('2026-09-15', '2026-09-30')));
        $this->assertSame(409, $refus->statutHttp);
        $this->assertStringContainsString('du 01/09/2026 au 15/09/2026', $refus->getMessage());
        $this->assertRegle('periode_chevauchante', fn () => $gestion->modifier($troisieme, ['date_ouverture' => '2026-10-10']));

        // Un autre internat n'est pas concerné par les dates de celui-ci.
        $gestion->creer($this->chefDePole, ['internats' => [['internat_id' => $this->filles->id, 'nombre_places' => 2]]] + $this->campagne('2026-09-01', '2026-09-15'));

        // L'écran de création accepte la deuxième campagne de l'année, sans le refus d'autrefois.
        $this->ecran($this->chefDePole, Campagnes::class)->call('preparer')
            ->set('nouvelle.annee', '2026-2027')->set('nouvelle.date_ouverture', '2026-12-01')->set('nouvelle.date_cloture', '2026-12-15')
            ->set('nouvelle.frais', '100')->set('places', [$this->garcons->id => '2'])->call('creer')->assertHasNoErrors();
        $this->assertSame(4, Campagne::where('internat_id', $this->garcons->id)->where('annee', '2026-2027')->count());
    }

    public function test_les_campagnes_se_filtrent_par_la_hierarchie_avec_les_filtres_existants(): void
    {
        $filles = $this->creerCampagne($this->filles);
        $garcons = $this->creerCampagne($this->garcons, ['statut' => StatutCampagne::Preparee]);
        $ailleurs = $this->creerCampagne($this->ailleurs);
        $complexe = $this->etablissement->complexe;
        $autreRegion = $this->ailleurs->etablissement->complexe->province->region;
        $memes = fn (array $ids) => fn ($liste) => collect($liste)->sort()->values()->all() === collect($ids)->sort()->values()->all();
        $listees = fn (array $campagnes) => fn ($page) => $memes(array_map(fn ($c) => $c->id, $campagnes))(collect($page->items())->pluck('id'));
        $proposes = fn (array $ids) => fn ($liste) => $memes($ids)($liste->keys());

        // Sans niveau choisi : toutes les campagnes, et le filtre « Internat » propose tout le périmètre, comme avant.
        $ecran = Livewire::actingAs($this->admin)->test(Campagnes::class)
            ->assertViewHas('campagnes', $listees([$filles, $garcons, $ailleurs]))
            ->assertViewHas('internats', $proposes([$this->filles->id, $this->garcons->id, $this->ailleurs->id]));

        // Région, puis établissement : les campagnes et les internats proposés se resserrent.
        $ecran->set('region', (string) $this->region->id)
            ->assertViewHas('campagnes', $listees([$filles, $garcons]))
            ->assertViewHas('internats', $proposes([$this->filles->id, $this->garcons->id]))
            ->set('province', (string) $complexe->province_id)->set('complexe', (string) $complexe->id)
            ->set('etablissement', (string) $this->etablissement->id)
            ->assertViewHas('internats', $proposes([$this->filles->id, $this->garcons->id]))
            ->set('internat', (string) $this->garcons->id)->assertViewHas('campagnes', $listees([$garcons]));

        // Les filtres existants s'y ajoutent : statut, campagnes archivées.
        $ecran->set('internat', '')->set('statut', StatutCampagne::Ouverte->value)->assertViewHas('campagnes', $listees([$filles]));
        $filles->forceFill(['date_deliberation' => now(), 'archivee_le' => now()])->save();
        $ecran->set('statut', '')->assertViewHas('campagnes', $listees([$garcons]))
            ->set('archives', true)->assertViewHas('campagnes', $listees([$filles, $garcons]));

        // Changer un niveau efface ceux d'en dessous, internat compris.
        $ecran->set('internat', (string) $this->filles->id)->set('region', (string) $autreRegion->id)
            ->assertSet('province', '')->assertSet('etablissement', '')->assertSet('internat', '')
            ->assertViewHas('campagnes', $listees([$ailleurs]));

        // Un internat donné seul dans l'adresse remplit la hiérarchie au-dessus de lui.
        Livewire::actingAs($this->admin)->withQueryParams(['internat' => (string) $this->garcons->id])->test(Campagnes::class)
            ->assertSet('region', (string) $this->region->id)->assertSet('etablissement', (string) $this->etablissement->id)
            ->assertViewHas('campagnes', $listees([$garcons]));
    }

    public function test_les_numeros_de_dossier_se_suivent_d_une_campagne_a_l_autre_et_une_seule_candidature_est_en_cours(): void
    {
        $premiere = $this->creerCampagne($this->filles, [
            'date_ouverture' => now()->subMonths(2)->toDateString(), 'date_cloture' => now()->subMonth()->toDateString(),
            'statut' => StatutCampagne::Cloturee, 'compteur_dossiers' => 3,
        ]);
        $seconde = $this->creerCampagne($this->filles);
        $dossiers = app(DossierStagiaire::class);

        // Un stagiaire dont la candidature de la première campagne est en cours ne candidate pas à la seconde.
        $stagiaire = $this->creerStagiaire($this->filles);
        $ancien = Demande::factory()->deposee()->create(['campagne_id' => $premiere->id, 'stagiaire_id' => $stagiaire->id, 'statut' => StatutDemande::EnAttente]);
        $this->agirEnTantQue($stagiaire);
        $this->assertRegle('candidature_en_cours', fn () => $dossiers->ouvrirBrouillon($stagiaire));

        // Une fois close, il peut candidater de nouveau.
        $ancien->forceFill(['statut' => StatutDemande::Refuse])->save();
        $brouillon = $dossiers->ouvrirBrouillon($stagiaire->fresh());
        $this->assertSame($seconde->id, $brouillon->campagne_id);

        // La numérotation se poursuit après le dernier numéro de l'année, sans reprendre à 1.
        $depose = $this->creerDossierRecu($seconde);
        $nouveau = Demande::create(['campagne_id' => $seconde->id, 'stagiaire_id' => $this->creerStagiaire($this->filles)->id, 'statut' => StatutDemande::Brouillon, 'adresse_complete' => '12 rue des Écoles']);
        foreach ($depose->pieces as $piece) {
            $nouveau->pieces()->create($piece->only(['type_piece_id', 'fichier', 'statut', 'date_depot', 'nom_original', 'mime', 'taille']));
        }
        $this->agirEnTantQue($nouveau->stagiaire);
        $dossiers->soumettre($nouveau->fresh());

        $this->assertStringEndsWith('-00004', $nouveau->fresh()->numero_dossier);
        $this->assertSame(4, $seconde->fresh()->compteur_dossiers);
    }
}
