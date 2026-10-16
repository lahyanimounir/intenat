<?php

use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\TypeNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Utilisateurs, stagiaires, traçabilité, notifications et paramètres.
 * La table `role` du dictionnaire est portée par Spatie Laravel Permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateur', function (Blueprint $table) {
            $table->id();
            // Périmètre : un seul niveau est renseigné selon le rôle du compte.
            $table->foreignId('internat_id')->nullable()->constrained('internat')->restrictOnDelete();
            $table->foreignId('etablissement_id')->nullable()->constrained('etablissement')->restrictOnDelete();
            $table->foreignId('complexe_id')->nullable()->constrained('complexe')->restrictOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('region')->restrictOnDelete();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email', 190)->unique();
            $table->string('mot_de_passe_hash');
            $table->boolean('actif')->default(true);
            $table->dateTime('derniere_connexion_le')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('stagiaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained('etablissement')->restrictOnDelete();
            $table->char('cef', 13)->unique();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email', 190)->unique();
            // Mot de passe local choisi à l'activation ; jamais celui de MyWay.
            $table->string('mot_de_passe_hash')->nullable();
            $table->string('cni', 20)->nullable();
            $table->string('tel', 30)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('nom_filiere', 190);
            $table->enum('niveau_formation', NiveauFormation::valeurs())->nullable();
            $table->enum('genre', Genre::valeurs())->nullable();
            $table->dateTime('date_import_myway');
            $table->dateTime('compte_active_le')->nullable();
            $table->dateTime('derniere_connexion_le')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['etablissement_id', 'genre']);
            $table->index(['nom', 'prenom']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('journal_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->foreignId('stagiaire_id')->nullable()->constrained('stagiaire')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('entite', 60);
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->dateTime('date_heure');
            $table->json('details')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->string('agent', 255)->nullable();

            $table->index(['entite', 'entite_id']);
            $table->index('date_heure');
            $table->index('action');
        });

        Schema::create('notification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->nullable()->constrained('stagiaire')->restrictOnDelete();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->enum('type', TypeNotification::valeurs());
            $table->string('titre', 160);
            $table->text('message');
            $table->string('lien', 255)->nullable();
            $table->boolean('lue')->default(false);
            $table->dateTime('date_envoi');
            $table->dateTime('date_lecture')->nullable();
            // Empêche qu'une tâche rejouée produise deux fois la même notification.
            $table->string('cle_unicite', 190)->nullable()->unique();
            // null : pas d'e-mail ; en_file, accepte (accepté par le serveur d'envoi), echec.
            $table->string('email_statut', 20)->nullable();

            $table->index(['utilisateur_id', 'lue', 'date_envoi']);
            $table->index(['stagiaire_id', 'lue', 'date_envoi']);
        });

        Schema::create('parametre', function (Blueprint $table) {
            $table->string('cle', 100)->primary();
            $table->json('valeur');
            $table->string('libelle', 255);
            // Vrai tant que la règle n'a pas été confirmée par l'OFPPT.
            $table->boolean('a_valider')->default(false);
            $table->foreignId('modifie_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametre');
        Schema::dropIfExists('notification');
        Schema::dropIfExists('journal_audit');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('stagiaire');
        Schema::dropIfExists('utilisateur');
    }
};
