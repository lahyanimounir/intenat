<?php

namespace Database\Factories;

use App\Enums\StatutCampagne;
use App\Models\Campagne;
use App\Models\Internat;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campagne> */
class CampagneFactory extends Factory
{
    protected $model = Campagne::class;

    public function definition(): array
    {
        return [
            'internat_id' => Internat::factory(),
            'annee' => '2026-2027',
            'date_ouverture' => now()->subDays(10)->toDateString(),
            'date_cloture' => now()->addDays(20)->toDateString(),
            'nombre_places' => 2,
            'frais_mensuels' => 100,
            'statut' => StatutCampagne::Ouverte,
            'cree_par' => Utilisateur::factory(),
        ];
    }

    public function preparee(): static
    {
        return $this->state(['statut' => StatutCampagne::Preparee]);
    }

    public function cloturee(): static
    {
        return $this->state([
            'statut' => StatutCampagne::Cloturee,
            'date_ouverture' => now()->subDays(30)->toDateString(),
            'date_cloture' => now()->subDay()->toDateString(),
        ]);
    }
}
