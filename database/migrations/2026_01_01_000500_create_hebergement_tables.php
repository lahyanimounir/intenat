<?php

use App\Enums\StatutImport;
use App\Enums\StatutMaintenance;
use App\Enums\StatutPaiement;
use App\Enums\StatutSortie;
use App\Enums\TypeDocument;
use App\Enums\TypeIncident;
use App\Enums\TypeMouvement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hébergement et paiement : affectations, mouvements, sorties, absences,
 * incidents, maintenance, documents, équipements et imports du référentiel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement', function (Blueprint $table) {
            $table->id();
            // Frais d'internat : un seul paiement par dossier (par campagne), non mensuel.
            $table->foreignId('demande_id')->unique()->constrained('demande')->restrictOnDelete();
            $table->decimal('montant', 8, 2);
            $table->string('recu_fichier', 255)->nullable();
            $table->string('recu_nom_original', 255)->nullable();
            $table->string('recu_mime', 100)->nullable();
            $table->enum('statut', StatutPaiement::valeurs())->default(StatutPaiement::EnAttente->value);
            $table->dateTime('date_autorisation')->nullable();
            $table->foreignId('autorise_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_depot')->nullable();
            $table->dateTime('date_validation')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->text('motif_refus')->nullable();
            $table->timestamps();

            $table->index('statut');
        });

        Schema::create('affectation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demande')->restrictOnDelete();
            $table->foreignId('lit_id')->constrained('lit')->restrictOnDelete();
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->foreignId('affecte_par')->constrained('utilisateur')->restrictOnDelete();
            $table->foreignId('cloture_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->text('motif_exceptionnel')->nullable();
            $table->timestamps();

            /*
             * Garde-fou d'intégrité : tant que `date_fin` est vide, l'affectation est
             * active. Les deux colonnes calculées ne portent une valeur que pour une
             * affectation active ; leur index unique interdit donc, au niveau de la
             * base, deux occupants actifs sur un lit et deux lits actifs pour un dossier.
             * (MySQL ne propose pas de contrainte d'exclusion.)
             */
            $table->unsignedBigInteger('lit_actif_id')->nullable()
                ->storedAs('IF(`date_fin` IS NULL, `lit_id`, NULL)');
            $table->unsignedBigInteger('demande_active_id')->nullable()
                ->storedAs('IF(`date_fin` IS NULL, `demande_id`, NULL)');

            $table->unique('lit_actif_id', 'affectation_lit_actif_unique');
            $table->unique('demande_active_id', 'affectation_demande_active_unique');
            $table->index(['lit_id', 'date_debut']);
        });

        Schema::create('mouvement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectation')->restrictOnDelete();
            $table->enum('type', TypeMouvement::valeurs());
            $table->dateTime('date_mouvement');
            $table->text('motif')->nullable();
            $table->foreignId('saisi_par')->constrained('utilisateur')->restrictOnDelete();

            $table->index(['affectation_id', 'date_mouvement']);
            $table->index(['type', 'date_mouvement']);
        });

        Schema::create('demande_sortie', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectation')->restrictOnDelete();
            $table->dateTime('date_depart');
            $table->dateTime('date_retour');
            $table->text('motif');
            $table->enum('statut', StatutSortie::valeurs())->default(StatutSortie::EnAttente->value);
            $table->foreignId('traite_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_traitement')->nullable();
            $table->text('motif_refus')->nullable();
            $table->timestamps();

            $table->index(['affectation_id', 'statut']);
            $table->index(['statut', 'date_depart']);
        });

        Schema::create('absence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectation')->restrictOnDelete();
            $table->foreignId('demande_sortie_id')->nullable()->constrained('demande_sortie')->restrictOnDelete();
            $table->date('date_absence');
            $table->boolean('autorisee');
            $table->foreignId('saisi_par')->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['affectation_id', 'date_absence']);
            $table->index(['date_absence', 'autorisee']);
        });

        Schema::create('incident', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affectation_id')->constrained('affectation')->restrictOnDelete();
            $table->enum('type', TypeIncident::valeurs());
            $table->text('description');
            $table->string('rapport_fichier', 255)->nullable();
            $table->dateTime('date_incident');
            $table->foreignId('saisi_par')->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();

            $table->index(['affectation_id', 'date_incident']);
        });

        Schema::create('demande_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chambre_id')->constrained('chambre')->restrictOnDelete();
            $table->text('description');
            $table->enum('statut', StatutMaintenance::valeurs())->default(StatutMaintenance::Signalee->value);
            $table->dateTime('date_demande');
            $table->foreignId('saisi_par')->constrained('utilisateur')->restrictOnDelete();
            // Vrai si la chambre est sortie de l'affectation le temps de la réparation.
            $table->boolean('bloque_chambre')->default(false);
            $table->dateTime('date_resolution')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resolu_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();

            $table->index(['chambre_id', 'statut']);
        });

        Schema::create('document', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->string('titre', 190);
            $table->enum('type', TypeDocument::valeurs());
            $table->string('fichier', 255);
            $table->string('nom_original', 255);
            $table->string('mime', 100);
            $table->dateTime('date_publication');
            $table->foreignId('publie_par')->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();

            $table->index(['internat_id', 'date_publication']);
        });

        // Extension : journal des imports CSV (aperçu, rapport d'erreurs, synthèse).
        Schema::create('import_referentiel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->foreignId('importe_par')->constrained('utilisateur')->restrictOnDelete();
            $table->string('fichier', 255);
            $table->string('nom_original', 255);
            $table->enum('statut', StatutImport::valeurs())->default(StatutImport::Analyse->value);
            $table->unsignedInteger('total_lignes')->default(0);
            $table->unsignedInteger('lignes_valides')->default(0);
            $table->unsignedInteger('lignes_erreur')->default(0);
            $table->json('rapport')->nullable();
            $table->timestamps();

            $table->index(['internat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_referentiel');
        Schema::dropIfExists('document');
        Schema::dropIfExists('demande_maintenance');
        Schema::dropIfExists('incident');
        Schema::dropIfExists('absence');
        Schema::dropIfExists('demande_sortie');
        Schema::dropIfExists('mouvement');
        Schema::dropIfExists('affectation');
        Schema::dropIfExists('paiement');
    }
};
