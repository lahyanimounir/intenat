<?php

use App\Enums\StatutExport;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Enums\TypeRepas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restauration (menus, réservations, service, régimes), exports asynchrones
 * et protection du journal d'audit contre toute modification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internat_id')->constrained('internat')->restrictOnDelete();
            $table->date('date_repas');
            $table->enum('type_repas', TypeRepas::valeurs());
            $table->text('description');
            $table->enum('statut', StatutMenu::valeurs())->default(StatutMenu::Propose->value);
            $table->dateTime('date_validation')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->foreignId('cree_par')->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['internat_id', 'date_repas', 'type_repas']);
            $table->index(['date_repas', 'statut']);
        });

        Schema::create('reservation_repas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menu')->restrictOnDelete();
            $table->foreignId('stagiaire_id')->constrained('stagiaire')->restrictOnDelete();
            $table->enum('statut', StatutReservation::valeurs())->default(StatutReservation::Reserve->value);
            $table->dateTime('date_reservation');
            $table->dateTime('date_annulation')->nullable();

            $table->unique(['menu_id', 'stagiaire_id']);
            $table->index(['stagiaire_id', 'statut']);
        });

        Schema::create('service_repas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->unique()->constrained('menu')->restrictOnDelete();
            $table->unsignedInteger('effectif_reserve')->default(0);
            $table->unsignedInteger('effectif_confirme')->nullable();
            $table->unsignedInteger('effectif_servi')->nullable();
            $table->boolean('conforme')->nullable();
            $table->date('date_service');
            $table->foreignId('confirme_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->dateTime('date_confirmation')->nullable();
            $table->dateTime('date_cloture_service')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index('date_service');
        });

        Schema::create('regime_alimentaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->constrained('stagiaire')->restrictOnDelete();
            $table->enum('type', TypeRegime::valeurs());
            $table->string('precision_regime', 255)->nullable();
            $table->string('justificatif_fichier', 255)->nullable();
            $table->timestamps();

            $table->unique(['stagiaire_id', 'type']);
        });

        Schema::create('export', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateur')->restrictOnDelete();
            $table->string('type', 60);
            $table->string('format', 10);
            $table->json('filtres')->nullable();
            $table->enum('statut', StatutExport::valeurs())->default(StatutExport::EnAttente->value);
            $table->string('fichier', 255)->nullable();
            $table->string('nom_fichier', 190)->nullable();
            $table->unsignedInteger('lignes')->nullable();
            $table->text('erreur')->nullable();
            $table->dateTime('termine_le')->nullable();
            $table->dateTime('expire_le')->nullable();
            $table->timestamps();

            $table->index(['utilisateur_id', 'created_at']);
        });

        // Le journal d'audit est en ajout seul : la base refuse UPDATE et DELETE.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER journal_audit_sans_modification BEFORE UPDATE ON journal_audit
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le journal d''audit ne peut pas etre modifie.'
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER journal_audit_sans_suppression BEFORE DELETE ON journal_audit
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Le journal d''audit ne peut pas etre supprime.'
            SQL);
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS journal_audit_sans_modification');
            DB::unprepared('DROP TRIGGER IF EXISTS journal_audit_sans_suppression');
        }

        Schema::dropIfExists('export');
        Schema::dropIfExists('regime_alimentaire');
        Schema::dropIfExists('service_repas');
        Schema::dropIfExists('reservation_repas');
        Schema::dropIfExists('menu');
    }
};
