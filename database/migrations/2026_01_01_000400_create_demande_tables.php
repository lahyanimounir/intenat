<?php

use App\Enums\DomaineReclamation;
use App\Enums\ListeAdmission;
use App\Enums\OrigineDecision;
use App\Enums\RaisonDesistement;
use App\Enums\StatutDemande;
use App\Enums\StatutPiece;
use App\Enums\StatutReclamation;
use App\Enums\TypeDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préinscription et sélection : dossier, pièces, détail du score, décisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_id')->constrained('campagne')->restrictOnDelete();
            $table->foreignId('stagiaire_id')->constrained('stagiaire')->restrictOnDelete();
            $table->string('numero_dossier', 40)->nullable()->unique();
            $table->enum('statut', StatutDemande::valeurs())->default(StatutDemande::Brouillon->value);
            $table->dateTime('date_soumission')->nullable();
            $table->text('adresse_complete')->nullable();
            $table->boolean('eloigne_ou_rural')->default(false);
            $table->boolean('demuni')->default(false);
            $table->boolean('orphelin_ou_divorce')->default(false);
            $table->boolean('besoins_specifiques')->default(false);
            $table->text('precisions_besoins')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedInteger('rang')->nullable();
            $table->enum('liste', ListeAdmission::valeurs())->nullable();
            $table->text('motif_rejet')->nullable();
            $table->text('motif_rectification')->nullable();
            $table->dateTime('date_confirmation')->nullable();
            $table->boolean('dossier_physique_depose')->default(false);
            $table->dateTime('date_depot_physique')->nullable();
            $table->foreignId('dossier_physique_valide_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->foreignId('instruit_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_instruction')->nullable();
            // Traçabilité du score : version de la grille et date de calcul.
            $table->unsignedInteger('version_grille')->nullable();
            $table->dateTime('date_calcul_score')->nullable();
            // Permission d'occuper : décision distincte du paiement (voir docs/decisions).
            $table->dateTime('occupation_autorisee_le')->nullable();
            $table->foreignId('occupation_autorisee_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->boolean('occupation_sans_recu')->nullable();
            $table->timestamps();

            $table->unique(['campagne_id', 'stagiaire_id']);
            $table->index(['campagne_id', 'statut']);
            $table->index(['campagne_id', 'liste', 'rang']);
            $table->index('date_soumission');
        });

        Schema::create('piece_jointe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demande')->restrictOnDelete();
            $table->foreignId('type_piece_id')->constrained('type_piece')->restrictOnDelete();
            $table->string('fichier', 255);
            $table->enum('statut', StatutPiece::valeurs())->default(StatutPiece::Deposee->value);
            $table->dateTime('date_depot');
            $table->string('nom_original', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('taille');
            $table->text('commentaire')->nullable();
            $table->foreignId('controle_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_controle')->nullable();
            $table->timestamps();

            $table->unique(['demande_id', 'type_piece_id']);
        });

        Schema::create('demande_critere', function (Blueprint $table) {
            $table->foreignId('demande_id')->constrained('demande')->cascadeOnDelete();
            $table->foreignId('critere_id')->constrained('critere')->restrictOnDelete();
            $table->unsignedTinyInteger('points_obtenus');
            $table->unsignedInteger('version_grille');

            $table->primary(['demande_id', 'critere_id']);
        });

        // Extension : historique des changements de statut (qui, quand, motif, avant, après).
        Schema::create('historique_demande', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demande')->cascadeOnDelete();
            $table->string('ancien_statut', 30)->nullable();
            $table->string('nouveau_statut', 30);
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->foreignId('stagiaire_id')->nullable()->constrained('stagiaire')->restrictOnDelete();
            $table->boolean('automatique')->default(false);
            $table->text('motif')->nullable();
            $table->dateTime('date_heure');

            $table->index(['demande_id', 'date_heure']);
        });

        Schema::create('decision_admission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('demande')->restrictOnDelete();
            $table->enum('origine', OrigineDecision::valeurs());
            $table->enum('decision', TypeDecision::valeurs());
            $table->text('motif')->nullable();
            $table->dateTime('date_validation');
            $table->foreignId('valide_par')->constrained('utilisateur')->restrictOnDelete();
            $table->unsignedSmallInteger('score_retenu')->nullable();
            $table->unsignedInteger('rang_retenu')->nullable();

            $table->index(['demande_id', 'date_validation']);
        });

        Schema::create('desistement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->unique()->constrained('demande')->restrictOnDelete();
            $table->enum('raison', RaisonDesistement::valeurs());
            $table->text('explication');
            $table->dateTime('date_desistement');
        });

        Schema::create('reclamation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->constrained('stagiaire')->restrictOnDelete();
            // Extension : internat concerné, pour router et cloisonner la réclamation.
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->enum('domaine', DomaineReclamation::valeurs());
            $table->text('description');
            $table->enum('statut', StatutReclamation::valeurs())->default(StatutReclamation::Ouverte->value);
            $table->text('reponse')->nullable();
            $table->dateTime('date_creation');
            $table->dateTime('date_traitement')->nullable();
            $table->foreignId('traite_par')->nullable()->constrained('utilisateur')->restrictOnDelete();

            $table->index(['internat_id', 'domaine', 'statut']);
            $table->index(['stagiaire_id', 'date_creation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclamation');
        Schema::dropIfExists('desistement');
        Schema::dropIfExists('decision_admission');
        Schema::dropIfExists('historique_demande');
        Schema::dropIfExists('demande_critere');
        Schema::dropIfExists('piece_jointe');
        Schema::dropIfExists('demande');
    }
};
