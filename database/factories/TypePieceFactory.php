<?php

namespace Database\Factories;

use App\Models\Campagne;
use App\Models\TypePiece;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TypePiece> */
class TypePieceFactory extends Factory
{
    protected $model = TypePiece::class;

    public function definition(): array
    {
        return [
            'campagne_id' => Campagne::factory(),
            'libelle' => 'Pièce '.fake()->unique()->numerify('######'),
            'obligatoire' => true,
            'formats_acceptes' => 'PDF, JPG, PNG, 5 Mo',
        ];
    }
}
