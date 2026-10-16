<?php

namespace Database\Factories;

use App\Enums\StatutLit;
use App\Models\Chambre;
use App\Models\Lit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lit> */
class LitFactory extends Factory
{
    protected $model = Lit::class;

    public function definition(): array
    {
        return [
            'chambre_id' => Chambre::factory(),
            'numero' => (string) fake()->unique()->numberBetween(1, 999999),
            'statut' => StatutLit::Disponible,
        ];
    }
}
