<?php

namespace App\Models;

use App\Enums\TypeMouvement;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relie un dossier admis à un lit pour une période. L'affectation active est
 * celle dont `date_fin` est vide ; les périodes passées sont conservées.
 *
 * @method static Builder<static> actives()
 */
class Affectation extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'affectation';

    protected $fillable = [
        'demande_id', 'lit_id', 'date_debut', 'date_fin', 'affecte_par', 'cloture_par', 'motif_exceptionnel',
    ];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public static function cheminInternat(): string
    {
        return 'demande.campagne';
    }

    public function scopeActives(Builder $requete): Builder
    {
        return $requete->whereNull($requete->qualifyColumn('date_fin'));
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function lit(): BelongsTo
    {
        return $this->belongsTo(Lit::class, 'lit_id');
    }

    public function affecteur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'affecte_par');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(Mouvement::class, 'affectation_id')->orderBy('date_mouvement');
    }

    public function sorties(): HasMany
    {
        return $this->hasMany(DemandeSortie::class, 'affectation_id');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class, 'affectation_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class, 'affectation_id');
    }

    public function estActive(): bool
    {
        return $this->date_fin === null;
    }

    /** Vrai une fois l'arrivée physique du résident enregistrée. */
    public function arriveeEnregistree(): bool
    {
        return $this->mouvements()->where('type', TypeMouvement::Arrivee->value)->exists();
    }
}
