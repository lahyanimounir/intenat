<?php

namespace Database\Factories;

use App\Enums\StatutChambre;
use App\Enums\TypeChambre;
use App\Models\Batiment;
use App\Models\Chambre;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Chambre> */
class ChambreFactory extends Factory
{
    protected $model = Chambre::class;

    public function definition(): array
    {
        return [
            'batiment_id' => Batiment::factory(),
            'numero' => 'CH-'.fake()->unique()->numerify('#####'),
            'etage' => 1,
            'type_chambre' => TypeChambre::Standard,
            'capacite' => 2,
            'statut' => StatutChambre::Disponible,
        ];
    }
}
