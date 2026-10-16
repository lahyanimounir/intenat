<?php

namespace Tests;

use Database\Seeders\RolesEtPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    /** Rôles, permissions et paramètres sont semés avec la base de test. */
    protected bool $seed = true;

    protected string $seeder = RolesEtPermissionsSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Aucun fichier de test n'est écrit sur le disque réel.
        Storage::fake('prive');

        // Les requêtes se présentent comme venant du SPA : session et CSRF Sanctum actifs.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
    }
}
