<?php

namespace App\Models;

use App\Enums\StatutReservation;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repas réservé par un stagiaire, avec l'allergie ou la restriction qu'il signale
 * pour ce repas précis.
 */
class ReservationRepas extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'reservation_repas';

    public $timestamps = false;

    protected $fillable = [
        'menu_id', 'stagiaire_id', 'statut', 'allergie', 'date_reservation', 'date_annulation',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutReservation::class,
            'date_reservation' => 'datetime',
            'date_annulation' => 'datetime',
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

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }
}
