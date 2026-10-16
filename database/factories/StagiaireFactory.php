<?php

namespace Database\Factories;

use App\Enums\AnneeFormation;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutInscription;
use App\Models\Etablissement;
use App\Models\Stagiaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stagiaire> */
class StagiaireFactory extends Factory
{
    protected $model = Stagiaire::class;

    public function definition(): array
    {
        return [
            'etablissement_id' => Etablissement::factory(),
            'cef' => fake()->unique()->numerify('2026#########'),
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'mot_de_passe_hash' => 'Motdepasse-Test-1',
            'cni' => 'ZZ'.fake()->unique()->numerify('######'),
            'tel' => '0600'.fake()->numerify('######'),
            'ville' => fake()->city(),
            'adresse' => fake()->streetAddress(),
            'nom_filiere' => 'Développement digital',
            'niveau_formation' => NiveauFormation::TechnicienSpecialise,
            'genre' => Genre::Fille,
            'annee_formation' => AnneeFormation::Premiere,
            'annee_scolaire' => '2026-2027',
            'source_identite' => 'declaration',
            'inscrit_le' => now(),
            'email_verifie_le' => now(),
            'profil_complete_le' => now(),
            // Par défaut, l'inscription en formation est vérifiée : c'est l'état d'un candidat à l'hébergement.
            'statut_inscription' => StatutInscription::Verifiee,
            'inscription_verifiee_le' => now(),
            'compte_active_le' => now(),
            'etat_compte' => EtatCompte::Actif,
        ];
    }

    /** Compte tout juste créé : ni identité, ni formation. */
    public function nouveau(): static
    {
        return $this->state(fn () => [
            'etablissement_id' => null, 'cef' => null, 'nom' => null, 'prenom' => null, 'cni' => null, 'tel' => null,
            'ville' => null, 'adresse' => null, 'nom_filiere' => null, 'niveau_formation' => null, 'genre' => null,
            'annee_formation' => null, 'annee_scolaire' => null, 'profil_complete_le' => null,
            'statut_inscription' => StatutInscription::NonDeclaree, 'inscription_verifiee_le' => null,
        ]);
    }

    /** Profil déclaré, à l'étape voulue de la vérification de l'inscription. */
    public function inscription(StatutInscription $statut): static
    {
        return $this->state(fn () => [
            'statut_inscription' => $statut,
            'inscription_verifiee_le' => $statut === StatutInscription::Verifiee ? now() : null,
        ]);
    }

    public function adresseNonVerifiee(): static
    {
        return $this->state(fn () => ['email_verifie_le' => null]);
    }
}
