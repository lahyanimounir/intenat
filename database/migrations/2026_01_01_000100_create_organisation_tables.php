<?php

use App\Enums\Genre;
use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutLit;
use App\Enums\TypeChambre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisation et référentiel des lits.
 * Région → (Complexe / CMC) → Établissement → Internat → Bâtiment → Chambre → Lit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 150);
            $table->timestamps();
        });

        // Extension documentée : niveau « complexe / CMC » entre la région et l'établissement.
        Schema::create('complexe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('region')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('nom', 150);
            $table->timestamps();
        });

        Schema::create('etablissement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('region')->restrictOnDelete();
            $table->foreignId('complexe_id')->nullable()->constrained('complexe')->restrictOnDelete();
            $table->string('code_efp', 20)->unique();
            // Nom tel qu'il apparaît sur MyWay : sert à retrouver le code EFP.
            $table->string('nom_efp', 190)->unique();
            $table->timestamps();
        });

        Schema::create('internat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissement')->restrictOnDelete();
            $table->enum('genre', Genre::valeurs());
            $table->string('nom', 150);
            $table->timestamps();

            // Un internat filles et un internat garçons par établissement.
            $table->unique(['etablissement_id', 'genre']);
        });

        Schema::create('batiment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->unsignedTinyInteger('nombre_etages')->default(0);
            $table->enum('statut', StatutBatiment::valeurs())->default(StatutBatiment::Ouvert->value);
            $table->text('equipements_communs')->nullable();
            $table->timestamps();

            $table->unique(['internat_id', 'code']);
        });

        Schema::create('chambre', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batiment_id')->constrained('batiment')->restrictOnDelete();
            $table->string('numero', 30);
            $table->unsignedTinyInteger('etage')->default(0);
            $table->enum('type_chambre', TypeChambre::valeurs())->default(TypeChambre::Standard->value);
            $table->unsignedTinyInteger('capacite');
            $table->text('equipements')->nullable();
            $table->enum('statut', StatutChambre::valeurs())->default(StatutChambre::Disponible->value);
            $table->timestamps();

            $table->unique(['batiment_id', 'numero']);
            $table->index(['batiment_id', 'etage']);
            $table->index('statut');
        });

        Schema::create('lit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chambre_id')->constrained('chambre')->restrictOnDelete();
            $table->string('numero', 10);
            $table->enum('statut', StatutLit::valeurs())->default(StatutLit::Disponible->value);
            $table->timestamps();

            $table->unique(['chambre_id', 'numero']);
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lit');
        Schema::dropIfExists('chambre');
        Schema::dropIfExists('batiment');
        Schema::dropIfExists('internat');
        Schema::dropIfExists('etablissement');
        Schema::dropIfExists('complexe');
        Schema::dropIfExists('region');
    }
};
