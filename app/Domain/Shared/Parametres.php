<?php

namespace App\Domain\Shared;

use App\Models\Parametre;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture des règles de gestion configurables. La table `parametre` prime ;
 * à défaut, la valeur initiale de config/internat.php s'applique.
 */
class Parametres
{
    /** @var array<string, mixed>|null */
    private ?array $valeurs = null;

    public function lire(string $cle): mixed
    {
        $this->valeurs ??= $this->charger();

        if (array_key_exists($cle, $this->valeurs)) {
            return $this->valeurs[$cle];
        }

        // Les clés contiennent des points : lecture directe du tableau, sans notation pointée.
        return config('internat.parametres')[$cle][0] ?? null;
    }

    public function entier(string $cle): int
    {
        return (int) $this->lire($cle);
    }

    public function vrai(string $cle): bool
    {
        return (bool) $this->lire($cle);
    }

    public function texte(string $cle): string
    {
        return (string) $this->lire($cle);
    }

    public function oublier(): void
    {
        $this->valeurs = null;
    }

    /** @return array<string, mixed> */
    private function charger(): array
    {
        if (! Schema::hasTable('parametre')) {
            return [];
        }

        return Parametre::query()->pluck('valeur', 'cle')->all();
    }
}
