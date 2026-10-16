<?php

namespace App\Models;

use App\Enums\StatutPaiement;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Frais d'internat d'une période : autorisation, dépôt du reçu, contrôle.
 */
class Paiement extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'paiement';

    protected $fillable = [
        'demande_id', 'periode', 'montant', 'recu_fichier', 'recu_nom_original', 'recu_mime', 'statut',
        'genere_auto', 'date_autorisation', 'autorise_par', 'date_depot', 'date_validation', 'valide_par',
        'motif_refus',
    ];

    protected $hidden = ['recu_fichier'];

    protected function casts(): array
    {
        return [
            'statut' => StatutPaiement::class,
            'montant' => 'decimal:2',
            'genere_auto' => 'boolean',
            'date_autorisation' => 'datetime',
            'date_depot' => 'datetime',
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

    public function autorisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'autorise_par');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'valide_par');
    }
}
