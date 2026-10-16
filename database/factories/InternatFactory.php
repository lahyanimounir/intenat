<?php

namespace Database\Factories;

use App\Enums\Genre;
use App\Models\Etablissement;
use App\Models\Internat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Internat> */
class InternatFactory extends Factory
{
    protected $model = Internat::class;

    public function definition(): array
    {
        return [
            'etablissement_id' => Etablissement::factory(),
            'genre' => Genre::Fille,
            'nom' => 'Internat '.fake()->unique()->numerify('######'),
        ];
    }
}
