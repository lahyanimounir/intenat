<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes de classement saisies à la main.
 *
 * Après la clôture d'une campagne et avant la délibération, le chef de pôle peut
 * rectifier la note d'un critère pour un dossier. L'override est conservé ici,
 * indépendamment du calcul automatique : le moteur de notation le consulte et le
 * fait primer, ce qui recalcule le score total et réordonne le classement. Une
 * ligne par (dossier, critère) ; sa suppression rétablit la note automatique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demande_note_manuelle', function (Blueprint $table) {
            $table->foreignId('demande_id')->constrained('demande')->cascadeOnDelete();
            $table->foreignId('critere_id')->constrained('critere')->cascadeOnDelete();
            $table->unsignedTinyInteger('points');
            $table->text('motif')->nullable();
            $table->foreignId('ajuste_par')->nullable()->constrained('utilisateur')->nullOnDelete();
            $table->dateTime('ajuste_le');

            $table->primary(['demande_id', 'critere_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demande_note_manuelle');
    }
};
