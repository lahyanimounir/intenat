<?php

namespace Tests\Unit;

use App\Domain\Applications\TransitionDemande;
use App\Domain\Audit\Journal;
use App\Domain\Identity\MyWay\ProfilMyWay;
use App\Domain\Identity\MyWay\SelectionCandidature;
use App\Domain\Shared\Documents;
use App\Enums\StatutDemande;
use Tests\TestCase;

/**
 * Règles sans accès à la base : éligibilité MyWay, formats de pièces, machine à états.
 */
class ReglesPuresTest extends TestCase
{
    protected bool $seed = false;

    public function test_la_candidature_inscrit_paye_est_reconnue_malgre_la_casse_et_les_accents(): void
    {
        $profil = new ProfilMyWay('2026000000001', 'Nom', 'Prénom', 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Préinscrit'],
            ['nom_efp' => 'EFP B', 'nom_filiere' => 'F2', 'statut' => '  INSCRIT   payé '],
        ]);

        $this->assertSame('EFP B', $profil->candidatureEligible('Inscrit Payé')['nom_efp']);
    }

    public function test_le_code_de_statut_renvoye_par_myway_vaut_son_libelle(): void
    {
        // MyWay renvoie des codes (« test_passe », « inscrit_paye ») là où ses écrans affichent un libellé.
        $profil = new ProfilMyWay('2026000000001', 'Nom', 'Prénom', 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'inscrit'],
            ['nom_efp' => 'EFP B', 'nom_filiere' => 'F2', 'statut' => 'inscrit_paye'],
        ]);

        $this->assertSame('EFP B', $profil->candidatureEligible('Inscrit Payé')['nom_efp']);
        $this->assertSame(ProfilMyWay::normaliser('Marrakech - Safi'), ProfilMyWay::normaliser('marrakech-safi'));
        $this->assertNotSame(ProfilMyWay::normaliser('inscrit'), ProfilMyWay::normaliser('inscrit_paye'));
    }

    public function test_aucune_candidature_eligible_si_aucun_statut_inscrit_paye(): void
    {
        $profil = new ProfilMyWay('2026000000001', 'Nom', 'Prénom', 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Inscrit'],
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Payé'],
        ]);

        $this->assertNull($profil->candidatureEligible('Inscrit Payé'));
    }

    public function test_une_seule_candidature_eligible_est_retenue_sans_ambiguite(): void
    {
        $profil = new ProfilMyWay('2026000000001', 'Nom', 'Prénom', 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Préinscrit'],
            ['nom_efp' => 'EFP B', 'nom_filiere' => 'F2', 'statut' => 'Inscrit Payé'],
            // La même inscription renvoyée deux fois n'en fait qu'une.
            ['nom_efp' => 'efp b', 'nom_filiere' => 'f2', 'statut' => 'inscrit_paye'],
        ]);

        $selection = SelectionCandidature::retenir($profil, 'Inscrit Payé');

        $this->assertNull($selection['motif']);
        $this->assertSame('EFP B', $selection['candidature']['nom_efp']);
    }

    public function test_plusieurs_inscriptions_eligibles_ne_sont_jamais_departagees_au_hasard(): void
    {
        $candidatures = fn (?string $anneeA, ?string $anneeB) => new ProfilMyWay('2026000000001', 'Nom', 'Prénom', 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Inscrit Payé', 'annee' => $anneeA],
            ['nom_efp' => 'EFP B', 'nom_filiere' => 'F2', 'statut' => 'Inscrit Payé', 'annee' => $anneeB],
        ]);

        // Aucune année, ou une année illisible : rien ne permet de choisir.
        $this->assertSame(SelectionCandidature::AMBIGUE, SelectionCandidature::retenir($candidatures(null, null), 'Inscrit Payé')['motif']);
        $this->assertSame(SelectionCandidature::AMBIGUE, SelectionCandidature::retenir($candidatures('2026-2027', null), 'Inscrit Payé')['motif']);
        $this->assertSame(SelectionCandidature::AMBIGUE, SelectionCandidature::retenir($candidatures('2026-2027', '2026/2027'), 'Inscrit Payé')['motif']);

        // Années lisibles et différentes : l'inscription de l'année la plus récente, quel que soit l'ordre de la réponse.
        $this->assertSame('EFP B', SelectionCandidature::retenir($candidatures('2024-2025', '2026-2027'), 'Inscrit Payé')['candidature']['nom_efp']);
        $this->assertSame('EFP A', SelectionCandidature::retenir($candidatures('2026', '2025-2026'), 'Inscrit Payé')['candidature']['nom_efp']);
    }

    public function test_sans_candidature_au_statut_exige_aucune_n_est_retenue(): void
    {
        $profil = new ProfilMyWay(null, null, null, 'a@b.test', null, null, null, [
            ['nom_efp' => 'EFP A', 'nom_filiere' => 'F1', 'statut' => 'Inscrit'],
        ]);

        $this->assertSame(['candidature' => null, 'motif' => SelectionCandidature::AUCUNE, 'eligibles' => 0], SelectionCandidature::retenir($profil, 'Inscrit Payé'));
    }

    public function test_les_formats_acceptes_sont_lus_et_bornes_par_la_configuration(): void
    {
        $documents = app(Documents::class);

        $this->assertSame(['extensions' => ['pdf', 'jpg', 'jpeg'], 'taille_max_ko' => 2048], $documents->regles('PDF, JPG, 2 Mo'));
        $this->assertSame(['pdf'], $documents->regles('pdf seulement')['extensions']);

        // Une taille supérieure au plafond global est ramenée au plafond.
        $this->assertSame((int) config('internat.documents.taille_max_ko'), $documents->regles('PDF, 500 Mo')['taille_max_ko']);

        // Un format inconnu ne peut pas élargir la liste autorisée.
        $this->assertSame(config('internat.documents.extensions'), $documents->regles('EXE, 1 Mo')['extensions']);
    }

    public function test_la_machine_a_etats_refuse_les_transitions_non_prevues(): void
    {
        $transitions = new TransitionDemande(app(Journal::class));

        $this->assertTrue($transitions->estPermise(StatutDemande::Brouillon, StatutDemande::Recu));
        $this->assertTrue($transitions->estPermise(StatutDemande::Recu, StatutDemande::Complet));
        $this->assertTrue($transitions->estPermise(StatutDemande::EnAttente, StatutDemande::Admis));
        $this->assertTrue($transitions->estPermise(StatutDemande::Admis, StatutDemande::DelaiDepasse));

        // Pas d'admission sans passer par « Complet », pas de retour arrière, états finaux figés.
        $this->assertFalse($transitions->estPermise(StatutDemande::Recu, StatutDemande::Admis));
        $this->assertFalse($transitions->estPermise(StatutDemande::Brouillon, StatutDemande::Complet));
        $this->assertFalse($transitions->estPermise(StatutDemande::Complet, StatutDemande::Recu));
        $this->assertFalse($transitions->estPermise(StatutDemande::Complet, StatutDemande::Confirme));

        foreach ([StatutDemande::Rejete, StatutDemande::Refuse, StatutDemande::Desiste, StatutDemande::DelaiDepasse, StatutDemande::SejourTermine] as $final) {
            foreach (StatutDemande::cases() as $cible) {
                $this->assertFalse($transitions->estPermise($final, $cible), "{$final->value} → {$cible->value}");
            }
        }
    }
}
