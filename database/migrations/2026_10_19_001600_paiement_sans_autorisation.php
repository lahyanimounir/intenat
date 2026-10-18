<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Le chef de pôle n'autorise plus les paiements : le stagiaire paie directement chaque mensualité.
 *  - les mensualités « autorisées » deviennent « en attente de paiement » (même sens désormais) ;
 *  - la permission « paiements.autoriser » disparaît ;
 *  - le paiement ne conditionne plus l'occupation : les deux règles paramétrables qui le liaient sont retirées.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('paiement')->where('statut', 'autorise')->update(['statut' => 'en_attente']);

        DB::table('parametre')->whereIn('cle', ['occupation.exiger_autorisation_paiement', 'occupation.exiger_recu_valide'])->delete();

        $permission = DB::table(config('permission.table_names.permissions'))->where('name', 'paiements.autoriser')->value('id');

        if ($permission !== null) {
            DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $permission)->delete();
            DB::table(config('permission.table_names.model_has_permissions'))->where('permission_id', $permission)->delete();
            DB::table(config('permission.table_names.permissions'))->where('id', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retour en arrière non reconstitué : l'autorisation par le chef de pôle n'a plus d'équivalent.
    }
};
