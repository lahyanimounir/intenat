<?php

namespace Database\Seeders;

use App\Domain\Identity\Permissions;
use App\Models\Parametre;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles, permissions et paramètres initiaux, dérivés de la matrice centralisée.
 * Rejouable : ajoute ce qui manque et réaligne les rôles sur la matrice.
 */
class RolesEtPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::catalogue()) as $code) {
            Permission::findOrCreate($code, 'web');
        }

        Permission::whereNotIn('name', array_keys(Permissions::catalogue()))->delete();

        foreach (Permissions::matrice() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        foreach (config('internat.parametres') as $cle => [$valeur, $libelle, $aValider]) {
            Parametre::firstOrCreate(['cle' => $cle], [
                'valeur' => $valeur, 'libelle' => $libelle, 'a_valider' => $aValider,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
