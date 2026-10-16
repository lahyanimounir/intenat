<?php

namespace Database\Factories;

use App\Models\Complexe;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Complexe> */
class ComplexeFactory extends Factory
{
    protected $model = Complexe::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'code' => 'C'.fake()->unique()->numerify('####'),
            'nom' => 'Complexe '.fake()->unique()->city(),
        ];
    }
}
