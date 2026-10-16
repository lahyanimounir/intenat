<?php

namespace Database\Factories;

use App\Enums\StatutBatiment;
use App\Models\Batiment;
use App\Models\Internat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Batiment> */
class BatimentFactory extends Factory
{
    protected $model = Batiment::class;

    public function definition(): array
    {
        return [
            'internat_id' => Internat::factory(),
            'code' => 'B'.fake()->unique()->numerify('###'),
            'nom' => 'Bloc '.fake()->unique()->numerify('###'),
            'nombre_etages' => 3,
            'statut' => StatutBatiment::Ouvert,
        ];
    }
}
