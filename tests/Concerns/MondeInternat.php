<?php

namespace Tests\Concerns;

use App\Domain\Accommodation\AffectationLits;
use App\Domain\Admissions\ConfirmationAdmission;
use App\Domain\Admissions\Deliberation;
use App\Domain\Applications\Instruction;
use App\Domain\Identity\Perimetre;
use App\Domain\Payments\SuiviPaiements;
use App\Domain\Shared\RegleMetierException;
use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\RoleCode;
use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Models\Affectation;
use App\Models\Batiment;
use App\Models\Campagne;
use App\Models\Chambre;
use App\Models\Complexe;
use App\Models\Critere;
use App\Models\Demande;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Lit;
use App\Models\PieceJointe;
use App\Models\Province;
use App\Models\Region;
use App\Models\Stagiaire;
use App\Models\TypePiece;
use App\Models\Utilisateur;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Petit monde de test : une région, un établissement, ses deux internats
 * (filles, garçons) et un compte par rôle, plus un second établissement
 * d'une autre région pour les tests de cloisonnement.
 */
trait MondeInternat
{
    protected Region $region;

    protected Etablissement $etablissement;

    protected Internat $filles;

    protected Internat $garcons;

    protected Internat $ailleurs;

    protected Utilisateur $admin;

    protected Utilisateur $gestionnaireFilles;

    protected Utilisateur $gestionnaireGarcons;

    protected Utilisateur $gestionnaireAilleurs;

    protected Utilisateur $chefDePole;

    protected Utilisateur $restauration;

    protected Utilisateur $directeur;

    protected Utilisateur $directionRegionale;

    protected Utilisateur $directionCentrale;

    protected function creerMonde(): void
    {
        $this->region = Region::factory()->create();
        // Hiérarchie stricte : l'établissement de test se rattache à sa région via province → complexe.
        $province = Province::factory()->create(['region_id' => $this->region->id]);
        $complexe = Complexe::factory()->create(['province_id' => $province->id]);
        $this->etablissement = Etablissement::factory()->create(['complexe_id' => $complexe->id, 'code_efp' => 'T100', 'nom_efp' => 'Établissement de test']);
        $this->filles = Internat::factory()->create(['etablissement_id' => $this->etablissement->id, 'genre' => Genre::Fille, 'nom' => 'Internat filles']);
        $this->garcons = Internat::factory()->create(['etablissement_id' => $this->etablissement->id, 'genre' => Genre::Garcon, 'nom' => 'Internat garçons']);

        $autre = Etablissement::factory()->create();
        $this->ailleurs = Internat::factory()->create(['etablissement_id' => $autre->id, 'genre' => Genre::Fille, 'nom' => 'Internat d\'une autre région']);

        $this->admin = Utilisateur::factory()->role(RoleCode::Admin)->create();
        $this->gestionnaireFilles = Utilisateur::factory()->gestionnaire($this->filles)->create();
        $this->gestionnaireGarcons = Utilisateur::factory()->gestionnaire($this->garcons)->create();
        $this->gestionnaireAilleurs = Utilisateur::factory()->gestionnaire($this->ailleurs)->create();
        $this->chefDePole = Utilisateur::factory()->chefDePole($this->etablissement->id)->create();
        $this->restauration = Utilisateur::factory()->restauration($this->etablissement->id)->create();
        $this->directeur = Utilisateur::factory()->directeur($this->etablissement->id)->create();
        $this->directionRegionale = Utilisateur::factory()->directionRegionale($this->region->id)->create();
        $this->directionCentrale = Utilisateur::factory()->role(RoleCode::DirCentrale)->create();

        app(Perimetre::class)->oublier();
    }

    /** @return Collection<int, Lit> */
    protected function creerLits(Internat $internat, int $chambres = 2, int $litsParChambre = 2): Collection
    {
        $batiment = Batiment::factory()->create(['internat_id' => $internat->id]);
        $lits = collect();

        for ($c = 1; $c <= $chambres; $c++) {
            $chambre = Chambre::factory()->create(['batiment_id' => $batiment->id, 'capacite' => $litsParChambre]);

            for ($l = 1; $l <= $litsParChambre; $l++) {
                $lits->push(Lit::factory()->create(['chambre_id' => $chambre->id, 'numero' => (string) $l]));
            }
        }

        return $lits;
    }

    /**
     * Campagne ouverte avec une pièce obligatoire (CIN) et trois critères :
     * démuni 20 pts (pièce exigée), éloigné 25 pts, niveau TS 10 pts.
     *
     * @param  array<string, mixed>  $attributs
     */
    protected function creerCampagne(Internat $internat, array $attributs = []): Campagne
    {
        $campagne = Campagne::factory()->create($attributs + ['internat_id' => $internat->id, 'cree_par' => $this->chefDePole->id]);

        TypePiece::factory()->create(['campagne_id' => $campagne->id, 'libelle' => 'Copie de la CIN', 'obligatoire' => true]);
        $sociale = TypePiece::factory()->create(['campagne_id' => $campagne->id, 'libelle' => 'Attestation de situation sociale', 'obligatoire' => false]);

        Critere::factory()->create(['campagne_id' => $campagne->id, 'type_piece_id' => $sociale->id]);
        Critere::factory()->create([
            'campagne_id' => $campagne->id, 'categorie' => CategorieCritere::Geographique, 'condition_texte' => 'Milieu rural ou éloigné',
            'points' => 25, 'declencheur' => DeclencheurCritere::EloigneOuRural,
        ]);
        Critere::factory()->create([
            'campagne_id' => $campagne->id, 'categorie' => CategorieCritere::Formation, 'condition_texte' => 'Niveau Technicien Spécialisé',
            'points' => 10, 'declencheur' => DeclencheurCritere::NiveauFormation, 'valeur_attendue' => NiveauFormation::TechnicienSpecialise->value,
        ]);

        return $campagne;
    }

    /** @param array<string, mixed> $attributs */
    protected function creerStagiaire(Internat $internat, array $attributs = []): Stagiaire
    {
        return Stagiaire::factory()->create($attributs + [
            'etablissement_id' => $internat->etablissement_id, 'genre' => $internat->genre,
        ]);
    }

    /**
     * Dossier déposé (statut « Reçu ») avec ses pièces requises.
     *
     * @param  array<string, mixed>  $declarations
     */
    protected function creerDossierRecu(Campagne $campagne, array $declarations = [], ?Stagiaire $stagiaire = null): Demande
    {
        $stagiaire ??= $this->creerStagiaire($campagne->internat);

        $demande = Demande::factory()->deposee()->create($declarations + [
            'campagne_id' => $campagne->id, 'stagiaire_id' => $stagiaire->id,
        ]);

        foreach ($campagne->typesPieces as $type) {
            if ($type->obligatoire || ($demande->demuni && $type->libelle === 'Attestation de situation sociale')) {
                $this->joindrePiece($demande, $type);
            }
        }

        return $demande;
    }

    protected function joindrePiece(Demande $demande, TypePiece $type, StatutPiece $statut = StatutPiece::Deposee): PieceJointe
    {
        $chemin = "pieces/{$demande->id}/{$type->id}.pdf";
        Storage::disk('prive')->put($chemin, '%PDF-1.4 pièce de test');

        return PieceJointe::create([
            'demande_id' => $demande->id, 'type_piece_id' => $type->id, 'fichier' => $chemin, 'statut' => $statut,
            'date_depot' => now(), 'nom_original' => 'piece.pdf', 'mime' => 'application/pdf', 'taille' => 24,
        ]);
    }

    /** Le gestionnaire accepte les pièces et déclare le dossier complet (score calculé). */
    protected function rendreComplet(Demande $demande, ?Utilisateur $gestionnaire = null): Demande
    {
        $gestionnaire ??= $demande->campagne->internat_id === $this->filles->id ? $this->gestionnaireFilles : $this->gestionnaireGarcons;
        $instruction = app(Instruction::class);

        $this->agirEnTantQue($gestionnaire);

        foreach ($demande->pieces as $piece) {
            $instruction->controlerPiece($piece, StatutPiece::Acceptee, null, $gestionnaire);
        }

        return $instruction->declarerComplet($demande->fresh(), $gestionnaire)->fresh();
    }

    /** Clôt la campagne puis fait valider les admissions par le chef de pôle. */
    protected function deliberer(Campagne $campagne): Campagne
    {
        $campagne->forceFill(['statut' => StatutCampagne::Cloturee, 'date_cloture' => now()->subDay()->toDateString()])->save();
        $this->agirEnTantQue($this->chefDePole);

        return app(Deliberation::class)->valider($campagne->fresh(), $this->chefDePole, [], true);
    }

    /** Admis confirmé, paiement autorisé, occupation autorisée : prêt à recevoir un lit. */
    protected function creerConfirme(Campagne $campagne): Demande
    {
        $demande = Demande::factory()->deposee(StatutDemande::Admis)->create([
            'campagne_id' => $campagne->id, 'stagiaire_id' => $this->creerStagiaire($campagne->internat)->id,
        ]);

        $this->agirEnTantQue($demande->stagiaire);
        app(ConfirmationAdmission::class)->confirmer($demande);

        $this->agirEnTantQue($this->chefDePole);
        app(SuiviPaiements::class)->autoriser($demande->paiements()->first(), $this->chefDePole);

        $gestionnaire = $campagne->internat_id === $this->filles->id ? $this->gestionnaireFilles : $this->gestionnaireGarcons;
        $this->agirEnTantQue($gestionnaire);

        return app(AffectationLits::class)->autoriserOccupation($demande->fresh(), $gestionnaire);
    }

    protected function creerResident(Campagne $campagne, Lit $lit): Affectation
    {
        $demande = $this->creerConfirme($campagne);
        $gestionnaire = $campagne->internat_id === $this->filles->id ? $this->gestionnaireFilles : $this->gestionnaireGarcons;
        $affectation = app(AffectationLits::class)->affecter($demande, $lit, now(), $gestionnaire);
        app(AffectationLits::class)->enregistrerArrivee($affectation, $gestionnaire);

        return $affectation->fresh();
    }

    /** Définit l'acteur vu par les services (journal, historique) hors requête HTTP. */
    protected function agirEnTantQue(Utilisateur|Stagiaire $acteur): void
    {
        Auth::guard('web')->forgetUser();
        Auth::guard('stagiaire')->forgetUser();
        Auth::guard($acteur instanceof Utilisateur ? 'web' : 'stagiaire')->setUser($acteur);
    }

    /**
     * Ouvre un écran Livewire pour ce compte, toute autre session fermée. La garde est nommée : après une
     * session de stagiaire, la garde par défaut ne désigne plus celle du personnel.
     *
     * @param  class-string  $ecran
     * @param  array<string, mixed>  $parametres
     */
    protected function ecran(Utilisateur|Stagiaire $compte, string $ecran, array $parametres = []): Testable
    {
        $this->app['auth']->forgetGuards();
        app(Perimetre::class)->oublier();

        return Livewire::actingAs($compte, $compte instanceof Utilisateur ? 'web' : 'stagiaire')->test($ecran, $parametres);
    }

    /** Vérifie qu'une action est refusée par la règle de gestion attendue. */
    protected function assertRegle(string $code, callable $action): RegleMetierException
    {
        try {
            $action();
        } catch (RegleMetierException $regle) {
            $this->assertSame($code, $regle->codeRegle, $regle->getMessage());

            return $regle;
        }

        $this->fail("La règle « {$code} » n'a pas été appliquée.");
    }

    protected function connecter(Utilisateur|Stagiaire $compte): static
    {
        // Entre deux comptes, on repart comme pour une nouvelle requête d'un autre navigateur :
        // gardes réinitialisées et session vide.
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        app(Perimetre::class)->oublier();

        return $this->actingAs($compte, $compte instanceof Utilisateur ? 'web' : 'stagiaire');
    }
}
