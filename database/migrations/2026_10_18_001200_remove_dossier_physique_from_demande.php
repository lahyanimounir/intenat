<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait du dossier physique (papier).
 *
 * Le dépôt du dossier papier par le stagiaire et sa validation par le
 * gestionnaire ne font plus partie du parcours : la confirmation de la place et
 * l'autorisation d'occupation suffisent. On supprime les colonnes et le réglage
 * de blocage d'occupation associés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demande', function (Blueprint $table) {
            $table->dropForeign(['dossier_physique_valide_par']);
            $table->dropColumn(['dossier_physique_depose', 'date_depot_physique', 'dossier_physique_valide_par']);
        });

        DB::table('parametre')->where('cle', 'occupation.exiger_dossier_physique')->delete();
    }

    public function down(): void
    {
        Schema::table('demande', function (Blueprint $table) {
            $table->boolean('dossier_physique_depose')->default(false);
            $table->dateTime('date_depot_physique')->nullable();
            $table->foreignId('dossier_physique_valide_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
        });
    }
};
