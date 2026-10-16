<?php

namespace App\Models;

use App\Enums\DomaineReclamation;
use App\Enums\StatutReclamation;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réclamation ou recours d'un stagiaire, orienté par domaine vers le bon responsable.
 */
class Reclamation extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'reclamation';

    public $timestamps = false;

    protected $fillable = [
        'stagiaire_id', 'internat_id', 'domaine', 'description', 'statut', 'reponse', 'date_creation',
        'date_traitement', 'traite_par',
    ];

    protected function casts(): array
    {
        return [
            'domaine' => DomaineReclamation::class,
            'statut' => StatutReclamation::class,
            'date_creation' => 'datetime',
            'date_traitement' => 'datetime',
        ];
    }

    public static function cheminInternat(): string
    {
        return '';
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }

    public function internat(): BelongsTo
    {
        return $this->belongsTo(Internat::class, 'internat_id');
    }

    public function traitant(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'traite_par');
    }
}
