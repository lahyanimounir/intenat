<?php

namespace Database\Factories;

use App\Enums\StatutDemande;
use App\Models\Campagne;
use App\Models\Demande;
use App\Models\Stagiaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Demande> */
class DemandeFactory extends Factory
{
    protected $model = Demande::class;

    public function definition(): array
    {
        return [
            'campagne_id' => Campagne::factory(),
            'stagiaire_id' => Stagiaire::factory(),
            'statut' => StatutDemande::Brouillon,
            'adresse_complete' => '12 rue de démonstration',
        ];
    }

    public function deposee(StatutDemande $statut = StatutDemande::Recu): static
    {
        return $this->state(fn () => [
            'statut' => $statut,
            'numero_dossier' => 'INT-TEST-'.fake()->unique()->numerify('#######'),
            'date_soumission' => now()->subDays(3),
        ]);
    }
}
