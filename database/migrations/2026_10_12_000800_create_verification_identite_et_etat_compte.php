<?php

use App\Enums\EtatCompte;
use App\Enums\MethodeVerification;
use App\Enums\ResultatVerification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Première connexion du stagiaire : la vérification d'identité est séparée du
 * compte.
 *
 *  - `verification_identite` porte une vérification en cours — identité vérifiée
 *    auprès du fournisseur, candidature éligible retenue, autorisation
 *    d'activation à usage unique. Ce n'est pas un compte : rien ne permet de
 *    s'y connecter.
 *  - `stagiaire` n'est écrite qu'à l'activation, dans la même transaction que le
 *    choix du mot de passe INTERNAT. `etat_compte` distingue un compte actif,
 *    un compte suspendu et les lignes héritées de l'ancien parcours.
 *
 * Aucun mot de passe, cookie de session ou jeton du fournisseur d'identité
 * n'est stocké ici ; le jeton d'activation n'est conservé que sous forme
 * d'empreinte, et le profil vérifié est chiffré puis effacé à l'activation.
 */
return new class extends Migration
{
    private const CONTRAINTE = 'stagiaire_etat_compte_coherent';

    public function up(): void
    {
        Schema::table('stagiaire', function (Blueprint $table) {
            // Année de formation de la candidature retenue, quand le fournisseur la communique.
            $table->string('annee_formation', 20)->nullable()->after('statut_myway');
            // Origine de chaque information : importée, déduite du référentiel, manquante ou déclarée par le stagiaire.
            $table->json('origine_champs')->nullable()->after('annee_formation');
            $table->enum('etat_compte', EtatCompte::valeurs())->default(EtatCompte::EnAttente->value)->after('compte_active_le');
            $table->dateTime('suspendu_le')->nullable()->after('etat_compte');
            $table->string('motif_suspension', 255)->nullable()->after('suspendu_le');
            $table->dateTime('mot_de_passe_modifie_le')->nullable()->after('motif_suspension');
        });

        // Une ligne marquée activée sans mot de passe n'a jamais pu servir à se connecter : elle repasse en attente.
        DB::table('stagiaire')->whereNotNull('compte_active_le')->whereNull('mot_de_passe_hash')->update(['compte_active_le' => null]);
        DB::table('stagiaire')->whereNotNull('compte_active_le')->update([
            'etat_compte' => EtatCompte::Actif->value,
            'mot_de_passe_modifie_le' => DB::raw('compte_active_le'),
        ]);

        // Un compte actif ou suspendu a toujours une date d'activation et un mot de passe local ; une ligne en attente n'a pas de date d'activation.
        DB::statement('ALTER TABLE stagiaire ADD CONSTRAINT '.self::CONTRAINTE." CHECK (
            (etat_compte = 'en_attente' AND compte_active_le IS NULL)
            OR (etat_compte <> 'en_attente' AND compte_active_le IS NOT NULL AND mot_de_passe_hash IS NOT NULL)
        )");

        Schema::create('verification_identite', function (Blueprint $table) {
            $table->id();
            // Adaptateur qui a produit la vérification : `mock`, `connecteur`, `approuve`…
            $table->string('fournisseur', 20);
            $table->enum('methode', [
                MethodeVerification::Identifiants->value, MethodeVerification::Redirection->value, MethodeVerification::Code->value,
            ]);
            // Vrai si l'identité est synthétique (simulateur) : jamais confondue avec une identité lue sur MyWay.
            $table->boolean('simulee')->default(false);
            $table->enum('resultat', ResultatVerification::valeurs());

            // Identité et candidature établies par le fournisseur — jamais saisies librement par le stagiaire.
            $table->char('cef', 13)->nullable();
            $table->string('email', 190)->nullable();
            // Nom, prénom, CNI, téléphone, ville et origine de chaque champ : chiffrés, effacés à l'activation.
            $table->text('profil')->nullable();
            $table->foreignId('etablissement_id')->nullable()->constrained('etablissement')->nullOnDelete();
            $table->string('nom_efp', 190)->nullable();
            $table->string('nom_filiere', 190)->nullable();
            $table->string('statut_candidature', 60)->nullable();
            $table->string('annee_formation', 20)->nullable();
            $table->json('champs_manquants')->nullable();

            // Autorisation d'activation : empreinte SHA-256 d'un jeton aléatoire, courte durée, usage unique.
            $table->char('jeton_hash', 64)->nullable()->unique();
            $table->dateTime('expire_le')->nullable();
            $table->dateTime('consommee_le')->nullable();
            $table->foreignId('stagiaire_id')->nullable()->constrained('stagiaire')->restrictOnDelete();

            // Examen par l'administration d'une vérification non aboutie.
            $table->dateTime('examinee_le')->nullable();
            $table->foreignId('examinee_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->string('note_examen', 255)->nullable();

            $table->string('adresse_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['cef', 'resultat']);
            $table->index(['resultat', 'examinee_le', 'created_at']);
            $table->index('expire_le');
        });

        // Jetons de réinitialisation des stagiaires, séparés de ceux du personnel : une même adresse peut exister des deux côtés.
        Schema::create('stagiaire_password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stagiaire_password_reset_tokens');
        Schema::dropIfExists('verification_identite');

        DB::statement('ALTER TABLE stagiaire DROP CONSTRAINT '.self::CONTRAINTE);

        Schema::table('stagiaire', function (Blueprint $table) {
            $table->dropColumn(['annee_formation', 'origine_champs', 'etat_compte', 'suspendu_le', 'motif_suspension', 'mot_de_passe_modifie_le']);
        });
    }
};
