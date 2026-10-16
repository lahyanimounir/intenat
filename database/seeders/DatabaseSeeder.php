<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Toujours : rôles, permissions, paramètres.
        $this->call(RolesEtPermissionsSeeder::class);

        // Jeu de démonstration synthétique, uniquement en mode démonstration.
        if (config('internat.mode_demo') && ! app()->isProduction()) {
            $this->call(DemonstrationSeeder::class);
        }
    }
}
