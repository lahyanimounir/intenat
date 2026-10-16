<?php

namespace App\Models;

use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exécution d'un repas : effectif réservé, confirmé (J+1), servi et conformité.
 */
class ServiceRepas extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'service_repas';

    protected $fillable = [
        'menu_id', 'effectif_reserve', 'effectif_confirme', 'effectif_servi', 'conforme', 'date_service',
        'confirme_par', 'date_confirmation', 'date_cloture_service', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'conforme' => 'boolean',
            'date_service' => 'date',
            'date_confirmation' => 'datetime',
            'date_cloture_service' => 'datetime',
            'effectif_reserve' => 'integer',
            'effectif_confirme' => 'integer',
            'effectif_servi' => 'integer',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'menu';
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function confirmateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'confirme_par');
    }
}
