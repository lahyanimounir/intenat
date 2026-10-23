<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Réinitialisation des mots de passe par les responsables, selon la hiérarchie :
 *  - un mot de passe fixé par un responsable est provisoire : son titulaire doit le changer à la connexion suivante ;
 *  - deux permissions : réinitialiser des comptes du personnel (administrateur, directeur, chef de pôle)
 *    ou des comptes stagiaires (administrateur, chef de pôle, gestionnaire, responsable restauration).
 */
return new class extends Migration
{
    private const DROITS = [
        'mots_de_passe.personnel' => ['ADMIN', 'DIRECTEUR', 'CHEF_POLE'],
        'mots_de_passe.stagiaires' => ['ADMIN', 'CHEF_POLE', 'GESTIONNAIRE', 'RESTAURATION'],
    ];

    public function up(): void
    {
        foreach (['utilisateur', 'stagiaire'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('mot_de_passe_provisoire')->default(false)->after('mot_de_passe_hash');
            });
        }

        $tables = config('permission.table_names');

        foreach (self::DROITS as $nom => $roles) {
            $permission = DB::table($tables['permissions'])->where('name', $nom)->value('id')
                ?? DB::table($tables['permissions'])->insertGetId(['name' => $nom, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

            foreach (DB::table($tables['roles'])->whereIn('name', $roles)->pluck('id') as $role) {
                DB::table($tables['role_has_permissions'])->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');
        $ids = DB::table($tables['permissions'])->whereIn('name', array_keys(self::DROITS))->pluck('id');
        DB::table($tables['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
        DB::table($tables['permissions'])->whereIn('id', $ids)->delete();

        foreach (['utilisateur', 'stagiaire'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('mot_de_passe_provisoire'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
