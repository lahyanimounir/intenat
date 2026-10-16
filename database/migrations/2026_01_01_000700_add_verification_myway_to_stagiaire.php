<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité de l'import d'identité : quel fournisseur a produit la ligne
 * (profil fictif du simulateur, ou lecture réelle de MyWay) et quel statut de
 * candidature il a renvoyé. Une identité simulée ne peut plus être confondue
 * avec une identité vérifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stagiaire', function (Blueprint $table) {
            // `mock` (simulateur), `connecteur` ou `approuve` (MyWay réel). NULL : ligne semée, jamais importée.
            $table->string('source_identite', 20)->nullable()->after('nom_filiere');
            // Statut de la candidature retenue, tel que le fournisseur l'a renvoyé au moment de l'import.
            $table->string('statut_myway', 60)->nullable()->after('source_identite');
        });
    }

    public function down(): void
    {
        Schema::table('stagiaire', function (Blueprint $table) {
            $table->dropColumn(['source_identite', 'statut_myway']);
        });
    }
};
