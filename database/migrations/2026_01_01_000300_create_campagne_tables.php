<?php

use App\Enums\CategorieCritere;
use App\Enums\DeclencheurCritere;
use App\Enums\StatutCampagne;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes, pièces exigées et grille de critères.
 * Les critères sont figés : définis au niveau national par la Direction centrale,
 * ils ne font l'objet d'aucune correction en cours de campagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->string('annee', 9);
            $table->date('date_ouverture');
            $table->date('date_cloture');
            $table->unsignedInteger('nombre_places');
            $table->enum('statut', StatutCampagne::valeurs())->default(StatutCampagne::Preparee->value);
            $table->foreignId('cree_par')->constrained('utilisateur')->restrictOnDelete();
            // Extensions documentées.
            $table->decimal('frais_mensuels', 8, 2)->nullable();
            $table->unsignedInteger('version_grille')->default(1);
            $table->unsignedInteger('compteur_dossiers')->default(0);
            $table->dateTime('date_deliberation')->nullable();
            $table->foreignId('delibere_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_publication')->nullable();
            $table->dateTime('archivee_le')->nullable();
            $table->timestamps();

            $table->unique(['internat_id', 'annee']);
            $table->index(['statut', 'date_cloture']);
        });

        Schema::create('type_piece', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_id')->constrained('campagne')->cascadeOnDelete();
            $table->string('libelle', 150);
            $table->boolean('obligatoire')->default(true);
            $table->string('formats_acceptes', 100)->default('PDF, JPG, PNG, 5 Mo');
            $table->timestamps();

            $table->unique(['campagne_id', 'libelle']);
        });

        // Extension : grille nationale de référence proposée par la Direction centrale.
        Schema::create('critere_reference', function (Blueprint $table) {
            $table->id();
            $table->enum('categorie', CategorieCritere::valeurs());
            $table->enum('declencheur', DeclencheurCritere::valeurs());
            $table->string('valeur_attendue', 40)->default('');
            $table->string('condition_texte', 255);
            $table->unsignedTinyInteger('points');
            $table->string('piece_libelle', 150)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('critere', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_id')->constrained('campagne')->cascadeOnDelete();
            $table->foreignId('type_piece_id')->nullable()->constrained('type_piece')->restrictOnDelete();
            $table->enum('categorie', CategorieCritere::valeurs());
            // Dictionnaire : `condition` (mot réservé MySQL, renommé).
            $table->string('condition_texte', 255);
            $table->unsignedTinyInteger('points');
            $table->enum('declencheur', DeclencheurCritere::valeurs());
            // Chaîne vide pour une déclaration oui/non ; code du niveau pour « Formation ».
            $table->string('valeur_attendue', 40)->default('');
            $table->timestamps();

            // Une seule règle par donnée du dossier : le calcul reste sans ambiguïté.
            $table->unique(['campagne_id', 'declencheur', 'valeur_attendue'], 'critere_regle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critere');
        Schema::dropIfExists('critere_reference');
        Schema::dropIfExists('type_piece');
        Schema::dropIfExists('campagne');
    }
};
