<?php

namespace App\Models;

use App\Enums\StatutMenu;
use App\Enums\TypeRepas;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Menu d'un repas, proposé puis validé par le responsable restauration.
 */
class Menu extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'menu';

    protected $fillable = [
        'internat_id', 'date_repas', 'type_repas', 'description', 'statut', 'date_validation',
        'valide_par', 'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'type_repas' => TypeRepas::class,
            'statut' => StatutMenu::class,
            'date_repas' => 'date',
            'date_validation' => 'datetime',
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

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ReservationRepas::class, 'menu_id');
    }

    public function service(): HasOne
    {
        return $this->hasOne(ServiceRepas::class, 'menu_id');
    }
}
