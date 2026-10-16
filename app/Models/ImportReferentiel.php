<?php

namespace App\Models;

use App\Enums\StatutImport;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Import CSV du référentiel d'un internat : analyse, rapport et résultat (extension documentée).
 */
class ImportReferentiel extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'import_referentiel';

    protected $fillable = [
        'internat_id', 'importe_par', 'fichier', 'nom_original', 'statut', 'total_lignes',
        'lignes_valides', 'lignes_erreur', 'rapport',
    ];

    protected $hidden = ['fichier'];

    protected function casts(): array
    {
        return [
            'statut' => StatutImport::class,
            'rapport' => 'array',
            'total_lignes' => 'integer',
            'lignes_valides' => 'integer',
            'lignes_erreur' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return '';
    }

    public function internat(): BelongsTo
    {
        return $this->belongsTo(Internat::class, 'internat_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'importe_par');
    }
}
