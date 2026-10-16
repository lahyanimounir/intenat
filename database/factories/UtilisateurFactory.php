<?php

namespace Database\Factories;

use App\Enums\RoleCode;
use App\Models\Internat;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Utilisateur> */
class UtilisateurFactory extends Factory
{
    protected $model = Utilisateur::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'mot_de_passe_hash' => 'Motdepasse-Test-1',
            'actif' => true,
        ];
    }

    /** Attribue un rôle une fois le compte créé. */
    public function role(RoleCode $role): static
    {
        return $this->afterCreating(fn (Utilisateur $u) => $u->syncRoles([$role->value]));
    }

    public function gestionnaire(Internat $internat): static
    {
        return $this->state(['internat_id' => $internat->id])->role(RoleCode::Gestionnaire);
    }

    public function chefDePole(int $etablissementId): static
    {
        return $this->state(['etablissement_id' => $etablissementId])->role(RoleCode::ChefPole);
    }

    public function restauration(int $etablissementId): static
    {
        return $this->state(['etablissement_id' => $etablissementId])->role(RoleCode::Restauration);
    }

    public function directeur(int $etablissementId): static
    {
        return $this->state(['etablissement_id' => $etablissementId])->role(RoleCode::Directeur);
    }

    public function directionRegionale(int $regionId): static
    {
        return $this->state(['region_id' => $regionId])->role(RoleCode::DirRegionale);
    }
}
