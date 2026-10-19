<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retour pour correction d'un menu (seul, avec sa journée ou avec sa semaine) : le
 * commentaire du chef de pôle, sa date et son auteur restent visibles du responsable
 * restauration jusqu'à la retransmission. L'historique complet est au journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->text('correction_motif')->nullable()->after('transmis_par');
            $table->dateTime('correction_le')->nullable()->after('correction_motif');
            $table->foreignId('correction_par')->nullable()->after('correction_le')->constrained('utilisateur')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('menu', function (Blueprint $table) {
            $table->dropForeign(['correction_par']);
            $table->dropColumn(['correction_motif', 'correction_le', 'correction_par']);
        });
    }
};
