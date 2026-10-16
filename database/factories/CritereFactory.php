<?php

namespace Database\Factories;

use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Models\Campagne;
use App\Models\Critere;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Critere> */
class CritereFactory extends Factory
{
    protected $model = Critere::class;

    public function definition(): array
    {
        return [
            'campagne_id' => Campagne::factory(),
            'categorie' => CategorieCritere::Social,
            'condition_texte' => 'Stagiaire démuni',
            'points' => 20,
            'declencheur' => DeclencheurCritere::Demuni,
            'valeur_attendue' => '',
        ];
    }
}
