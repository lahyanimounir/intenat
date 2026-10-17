<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Province : niveau entre la région et le complexe.
 * Une province appartient à une région et regroupe plusieurs complexes.
 */
class Province extends Model
{
    use HasFactory;

    protected $table = 'province';

    protected $fillable = [
        'region_id', 'code', 'nom',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function complexes(): HasMany
    {
        return $this->hasMany(Complexe::class, 'province_id');
    }

    /** Établissements de la province, à travers ses complexes. */
    public function etablissements(): HasManyThrough
    {
        return $this->hasManyThrough(Etablissement::class, Complexe::class, 'province_id', 'complexe_id');
    }
}
