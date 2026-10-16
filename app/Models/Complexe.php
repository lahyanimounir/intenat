<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Complexe / CMC (extension documentée) : regroupe des établissements d'une région.
 */
class Complexe extends Model
{
    use HasFactory;

    protected $table = 'complexe';

    protected $fillable = [
        'region_id', 'code', 'nom',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function etablissements(): HasMany
    {
        return $this->hasMany(Etablissement::class, 'complexe_id');
    }
}
