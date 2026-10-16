<?php

use App\Enums\OrigineVerificationInscription;
use App\Enums\StatutInscription;
use App\Enums\StatutVerificationInscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription manuelle des stagiaires — abandon de l'intégration MyWay.
 *
 * Le stagiaire crée lui-même son compte (adresse et mot de passe), puis déclare
 * son identité et sa formation. Rien de ce qu'il déclare ne vaut preuve : son
 * inscription en formation (« Inscrit Payé ») est vérifiée par un agent habilité,
 * sur justificatif, et chaque décision est conservée.
 *
 *  - `stagiaire` : la ligne existe dès la création du compte ; identité et
 *    formation deviennent donc facultatives jusqu'à leur déclaration. S'y ajoutent
 *    la date d'inscription, la vérification de l'adresse, l'adresse postale,
 *    l'année scolaire et l'état de la vérification de l'inscription.
 *  - `verification_inscription` : une ligne par soumission ou par décision, avec
 *    le justificatif, l'agent, la date et le motif.
 *
 * Reprise de l'existant — aucun compte n'est supprimé ni déclassé :
 *  - une identité déjà établie par l'ancien parcours (première connexion MyWay)
 *    reste « vérifiée », et une ligne d'origine « reprise », sans agent, garde la
 *    trace de cette provenance pour qu'un agent puisse la réexaminer ;
 *  - l'adresse d'un compte déjà activé est réputée vérifiée (elle venait du
 *    fournisseur d'identité) ;
 *  - les tables et colonnes de l'ancien parcours (`verification_identite`,
 *    `source_identite`, `statut_myway`, `date_import_myway`) sont conservées
 *    comme historique et ne sont plus écrites.
 *
 * Cette vérification concerne les frais de scolarité. Elle est sans rapport avec
 * le paiement de l'hébergement (`paiement`), qui suit l'admission.
 */
return new class extends Migration
{
    private const CONTRAINTE_INSCRIPTION = 'stagiaire_inscription_coherente';

    private const CONTRAINTE_DECLARATION = 'stagiaire_declaration_complete';

    public function up(): void
    {
        Schema::table('stagiaire', function (Blueprint $table) {
            // Renseignés par le stagiaire après la création du compte, et non plus importés.
            $table->unsignedBigInteger('etablissement_id')->nullable()->change();
            $table->char('cef', 13)->nullable()->change();
            $table->string('nom', 100)->nullable()->change();
            $table->string('prenom', 100)->nullable()->change();
            $table->string('nom_filiere', 190)->nullable()->change();
            $table->dateTime('date_import_myway')->nullable()->change();

            $table->string('adresse', 255)->nullable()->after('ville');
            // Année scolaire de l'inscription déclarée, par exemple « 2026-2027 ».
            $table->string('annee_scolaire', 9)->nullable()->after('annee_formation');

            $table->dateTime('inscrit_le')->nullable()->after('date_import_myway');
            $table->dateTime('email_verifie_le')->nullable()->after('inscrit_le');
            $table->dateTime('profil_complete_le')->nullable()->after('email_verifie_le');

            $table->enum('statut_inscription', StatutInscription::valeurs())
                ->default(StatutInscription::NonDeclaree->value)->after('profil_complete_le');
            $table->dateTime('inscription_verifiee_le')->nullable()->after('statut_inscription');

            $table->index(['statut_inscription', 'etablissement_id']);
        });

        Schema::create('verification_inscription', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stagiaire_id')->constrained('stagiaire')->restrictOnDelete();
            $table->enum('origine', OrigineVerificationInscription::valeurs());
            $table->enum('statut', StatutVerificationInscription::valeurs());

            // Ce qui a été soumis à la vérification, figé au moment de la soumission.
            $table->char('cef', 13)->nullable();
            $table->foreignId('etablissement_id')->nullable()->constrained('etablissement')->restrictOnDelete();
            $table->string('nom_filiere', 190)->nullable();
            $table->string('niveau_formation', 40)->nullable();
            $table->string('annee_formation', 20)->nullable();
            $table->string('annee_scolaire', 9)->nullable();

            // Justificatif d'inscription, conservé en espace privé.
            $table->string('fichier', 255)->nullable();
            $table->string('nom_original', 255)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('taille')->nullable();

            $table->dateTime('soumise_le');
            $table->dateTime('decidee_le')->nullable();
            $table->foreignId('decidee_par')->nullable()->constrained('utilisateur')->restrictOnDelete();
            $table->string('motif', 500)->nullable();
            $table->timestamps();

            $table->index(['stagiaire_id', 'statut']);
            $table->index(['statut', 'soumise_le']);
            $table->index(['etablissement_id', 'statut']);
        });

        $this->reprendreLesComptesExistants();

        // Une inscription vérifiée porte la date de sa vérification.
        DB::statement('ALTER TABLE stagiaire ADD CONSTRAINT '.self::CONTRAINTE_INSCRIPTION." CHECK (
            statut_inscription <> 'verifiee' OR inscription_verifiee_le IS NOT NULL
        )");

        // Dès qu'une inscription est déclarée, l'identité et la formation sont renseignées.
        DB::statement('ALTER TABLE stagiaire ADD CONSTRAINT '.self::CONTRAINTE_DECLARATION." CHECK (
            statut_inscription = 'non_declaree'
            OR (cef IS NOT NULL AND nom IS NOT NULL AND prenom IS NOT NULL AND etablissement_id IS NOT NULL AND nom_filiere IS NOT NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stagiaire DROP CONSTRAINT '.self::CONTRAINTE_DECLARATION);
        DB::statement('ALTER TABLE stagiaire DROP CONSTRAINT '.self::CONTRAINTE_INSCRIPTION);

        Schema::dropIfExists('verification_inscription');

        Schema::table('stagiaire', function (Blueprint $table) {
            $table->dropIndex(['statut_inscription', 'etablissement_id']);
            $table->dropColumn([
                'adresse', 'annee_scolaire', 'inscrit_le', 'email_verifie_le', 'profil_complete_le',
                'statut_inscription', 'inscription_verifiee_le',
            ]);
        });

        // Les colonnes d'identité restent facultatives : des comptes créés depuis cette migration
        // peuvent ne pas les avoir renseignées, et les rendre obligatoires de nouveau échouerait.
    }

    /** Les comptes issus de l'ancien parcours gardent leur inscription vérifiée, avec la trace de son origine. */
    private function reprendreLesComptesExistants(): void
    {
        $maintenant = now();

        // L'ancienne « année de formation » portait en réalité l'année scolaire communiquée par le fournisseur.
        DB::table('stagiaire')->whereNotNull('annee_formation')->lazyById()->each(function (object $ligne) {
            $annee = trim((string) $ligne->annee_formation);
            $scolaire = match (true) {
                preg_match('/^(\d{4})\D+(\d{4})$/', $annee, $m) === 1 => "{$m[1]}-{$m[2]}",
                preg_match('/^\d{4}$/', $annee) === 1 => $annee.'-'.((int) $annee + 1),
                default => null,
            };

            DB::table('stagiaire')->where('id', $ligne->id)->update(['annee_scolaire' => $scolaire, 'annee_formation' => null]);
        });

        DB::table('stagiaire')->update([
            'inscrit_le' => DB::raw('COALESCE(compte_active_le, date_import_myway, created_at)'),
            'profil_complete_le' => DB::raw('COALESCE(date_import_myway, created_at)'),
            'email_verifie_le' => DB::raw('compte_active_le'),
            'statut_inscription' => StatutInscription::Verifiee->value,
            'inscription_verifiee_le' => DB::raw('COALESCE(date_import_myway, compte_active_le, created_at)'),
        ]);

        DB::table('stagiaire')->lazyById()->each(function (object $ligne) use ($maintenant) {
            DB::table('verification_inscription')->insert([
                'stagiaire_id' => $ligne->id,
                'origine' => OrigineVerificationInscription::Reprise->value,
                'statut' => StatutVerificationInscription::Verifiee->value,
                'cef' => $ligne->cef,
                'etablissement_id' => $ligne->etablissement_id,
                'nom_filiere' => $ligne->nom_filiere,
                'niveau_formation' => $ligne->niveau_formation,
                'annee_formation' => $ligne->annee_formation,
                'annee_scolaire' => $ligne->annee_scolaire,
                'soumise_le' => $ligne->inscription_verifiee_le,
                'decidee_le' => $ligne->inscription_verifiee_le,
                'decidee_par' => null,
                'motif' => 'Inscription établie lors de la première connexion par MyWay (source : '.($ligne->source_identite ?? 'inconnue')
                    .'). Reprise à l\'abandon de MyWay : à réexaminer par un agent habilité.',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        });
    }
};
