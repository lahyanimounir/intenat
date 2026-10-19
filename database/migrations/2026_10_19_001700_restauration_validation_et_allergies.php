<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Restauration :
 *  - le menu de la semaine est transmis par le responsable restauration au chef de pôle, qui le valide ;
 *  - chaque réservation peut porter une allergie ou une restriction propre au repas ;
 *  - deux types de régime s'ajoutent : végétarien et autre restriction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->dateTime('transmis_le')->nullable()->after('statut');
            $table->foreignId('transmis_par')->nullable()->after('transmis_le')->constrained('utilisateur')->restrictOnDelete();
        });

        Schema::table('reservation_repas', function (Blueprint $table) {
            $table->string('allergie', 255)->nullable()->after('statut');
        });

        Schema::table('regime_alimentaire', function (Blueprint $table) {
            $table->enum('type', ['allergie', 'restriction_medicale', 'restriction_religieuse', 'vegetarien', 'autre'])->change();
        });

        // Le chef de pôle valide désormais les menus ; le responsable restauration les prépare et les transmet.
        $tables = config('permission.table_names');
        $permission = DB::table($tables['permissions'])->where('name', 'restauration.valider_menus')->value('id')
            ?? DB::table($tables['permissions'])->insertGetId(['name' => 'restauration.valider_menus', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        $chefDePole = DB::table($tables['roles'])->where('name', 'CHEF_POLE')->value('id');

        if ($chefDePole !== null && ! DB::table($tables['role_has_permissions'])->where(['permission_id' => $permission, 'role_id' => $chefDePole])->exists()) {
            DB::table($tables['role_has_permissions'])->insert(['permission_id' => $permission, 'role_id' => $chefDePole]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('regime_alimentaire')->whereIn('type', ['vegetarien', 'autre'])->update(['type' => 'restriction_medicale']);

        Schema::table('regime_alimentaire', function (Blueprint $table) {
            $table->enum('type', ['allergie', 'restriction_medicale', 'restriction_religieuse'])->change();
        });

        Schema::table('reservation_repas', function (Blueprint $table) {
            $table->dropColumn('allergie');
        });

        Schema::table('menu', function (Blueprint $table) {
            $table->dropForeign(['transmis_par']);
            $table->dropColumn(['transmis_le', 'transmis_par']);
        });
    }
};
