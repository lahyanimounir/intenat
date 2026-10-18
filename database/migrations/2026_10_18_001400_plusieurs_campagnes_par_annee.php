<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plusieurs campagnes d'inscription par internat et par année scolaire : l'unicité
 * (internat, année) est levée. Les périodes de dépôt d'un même internat ne se
 * chevauchent pas ; la règle est tenue par GestionCampagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            // L'index simple est posé d'abord : il remplace l'unique dans son rôle d'index de la clé étrangère.
            $table->index(['internat_id', 'annee']);
        });

        Schema::table('campagne', function (Blueprint $table) {
            $table->dropUnique(['internat_id', 'annee']);
        });
    }

    public function down(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            $table->unique(['internat_id', 'annee']);
        });

        Schema::table('campagne', function (Blueprint $table) {
            $table->dropIndex(['internat_id', 'annee']);
        });
    }
};
