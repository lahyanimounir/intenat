<?php

namespace Database\Factories;

use App\Models\Province;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Province> */
class ProvinceFactory extends Factory
{
    protected $model = Province::class;

    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'code' => 'P'.fake()->unique()->numerify('####'),
            'nom' => 'Province '.fake()->unique()->city(),
        ];
    }
}
