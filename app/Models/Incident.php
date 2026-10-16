<?php

namespace App\Models;

use App\Enums\TypeIncident;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incident ou fait disciplinaire, avec le rapport du conseil s'il existe.
 */
class Incident extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'incident';

    protected $fillable = [
        'affectation_id', 'type', 'description', 'rapport_fichier', 'date_incident', 'saisi_par',
    ];

    protected $hidden = ['rapport_fichier'];

    protected function casts(): array
    {
        return [
            'type' => TypeIncident::class,
            'date_incident' => 'datetime',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'affectation.demande.campagne';
    }

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'saisi_par');
    }
}
