<?php

namespace App\Models;

use App\Enums\OrigineDecision;
use App\Enums\TypeDecision;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Validation du chef de pôle scolarité, à la sélection initiale ou depuis la liste d'attente.
 */
class DecisionAdmission extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'decision_admission';

    public $timestamps = false;

    protected $fillable = [
        'demande_id', 'origine', 'decision', 'motif', 'date_validation', 'valide_par', 'score_retenu',
        'rang_retenu',
    ];

    protected function casts(): array
    {
        return [
            'origine' => OrigineDecision::class,
            'decision' => TypeDecision::class,
            'date_validation' => 'datetime',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'demande.campagne';
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class, 'demande_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }
}
