<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frais d'internat mensuels : un paiement par dossier et par mois de séjour (« AAAA-MM »).
 * Le montant de la campagne est le montant mensuel de référence. Les paiements existants
 * sont rattachés au mois de leur autorisation (à défaut, de leur création).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement', function (Blueprint $table) {
            $table->string('periode', 7)->nullable()->after('demande_id');
            $table->boolean('genere_auto')->default(false)->after('statut');
        });

        DB::table('paiement')->orderBy('id')->lazyById()->each(function (object $ligne) {
            $reference = $ligne->date_autorisation ?? $ligne->created_at ?? now();
            DB::table('paiement')->where('id', $ligne->id)->update(['periode' => date('Y-m', strtotime((string) $reference))]);
        });

        Schema::table('paiement', function (Blueprint $table) {
            $table->string('periode', 7)->nullable(false)->change();
            // Une seule ligne par dossier et par mois : la génération mensuelle est rejouable.
            // Posée avant le retrait de l'ancien index unique, elle sert aussi d'index à la clé étrangère.
            $table->unique(['demande_id', 'periode']);
            $table->index(['periode', 'statut']);
        });

        Schema::table('paiement', function (Blueprint $table) {
            $table->dropUnique(['demande_id']);
        });
    }

    public function down(): void
    {
        Schema::table('paiement', function (Blueprint $table) {
            $table->unique('demande_id');
        });

        Schema::table('paiement', function (Blueprint $table) {
            $table->dropIndex(['periode', 'statut']);
            $table->dropUnique(['demande_id', 'periode']);
            $table->dropColumn(['periode', 'genere_auto']);
        });
    }
};
