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
 * Menu d'un repas : proposé par le responsable restauration, transmis au chef
 * de pôle avec la semaine, puis validé par celui-ci. Seul un menu validé se réserve.
 */
class Menu extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'menu';

    protected $fillable = [
        'internat_id', 'date_repas', 'type_repas', 'description', 'statut', 'transmis_le', 'transmis_par',
        'date_validation', 'valide_par', 'cree_par',
    ];

    protected function casts(): array
    {
        return [
            'type_repas' => TypeRepas::class,
            'statut' => StatutMenu::class,
            'date_repas' => 'date',
            'transmis_le' => 'datetime',
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

    /** Proposé et transmis au chef de pôle, en attente de sa validation. */
    public function enAttenteDeValidation(): bool
    {
        return $this->statut === StatutMenu::Propose && $this->transmis_le !== null;
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
