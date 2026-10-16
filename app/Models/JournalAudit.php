<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Trace des actions importantes : qui a fait quoi, quand, sur quelle ligne.
 * Table en ajout seul : ni le modèle ni la base (déclencheurs) n'acceptent
 * de modification ou de suppression.
 */
class JournalAudit extends Model
{
    protected $table = 'journal_audit';

    public $timestamps = false;

    protected $fillable = [
        'utilisateur_id', 'stagiaire_id', 'action', 'entite', 'entite_id', 'date_heure', 'details',
        'adresse_ip', 'agent',
    ];

    protected function casts(): array
    {
        return ['date_heure' => 'datetime', 'details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'audit ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'audit ne peut pas être supprimé.'));
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }
}
