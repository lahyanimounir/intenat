<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message général aux stagiaires, rédigé au moment de la délibération.
 *
 * Unique pour toute la campagne, il est affiché dans le compte de chaque
 * candidat (admis, liste d'attente, refusé) une fois les résultats publiés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            $table->text('message_deliberation')->nullable()->after('date_publication');
        });
    }

    public function down(): void
    {
        Schema::table('campagne', function (Blueprint $table) {
            $table->dropColumn('message_deliberation');
        });
    }
};
