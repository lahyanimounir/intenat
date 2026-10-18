<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retrait du réglage « inscription.depot_exige_verification ».
 *
 * Dans la candidature unifiée, l'inscription en formation et le dossier sont
 * soumis ensemble puis examinés en une seule revue par le gestionnaire : exiger
 * que l'inscription soit déjà vérifiée avant le dépôt du dossier est impossible
 * à satisfaire et bloque la soumission. Le réglage n'a plus de sens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parametre')->where('cle', 'inscription.depot_exige_verification')->delete();
    }

    public function down(): void
    {
        // Réglage obsolète : rien à rétablir.
    }
};
