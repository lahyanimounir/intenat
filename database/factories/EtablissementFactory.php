<?php

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Etablissement> */
class EtablissementFactory extends Factory
{
    protected $model = Etablissement::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'code_efp' => 'E'.fake()->unique()->numerify('####'),
            'nom_efp' => 'Établissement '.fake()->unique()->numerify('######'),
        ];
    }
}
