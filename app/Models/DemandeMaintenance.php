<?php

namespace App\Models;

use App\Enums\StatutMaintenance;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de réparation d'une chambre ; peut sortir la chambre de l'affectation.
 */
class DemandeMaintenance extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'demande_maintenance';

    protected $fillable = [
        'chambre_id', 'description', 'statut', 'date_demande', 'saisi_par', 'bloque_chambre',
        'date_resolution', 'resolution', 'resolu_par',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutMaintenance::class,
            'date_demande' => 'datetime',
            'date_resolution' => 'datetime',
            'bloque_chambre' => 'boolean',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'chambre.batiment';
    }

    public function chambre(): BelongsTo
    {
        return $this->belongsTo(Chambre::class, 'chambre_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }
}
