<?php

namespace App\Models;

use App\Enums\TypeNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message conservé pour un stagiaire ou un membre du personnel (table `notification`).
 */
class NotificationInterne extends Model
{
    use HasFactory;

    protected $table = 'notification';

    public $timestamps = false;

    protected $fillable = [
        'stagiaire_id', 'utilisateur_id', 'type', 'titre', 'message', 'lien', 'lue', 'date_envoi',
        'date_lecture', 'cle_unicite', 'email_statut',
    ];

    protected function casts(): array
    {
        return [
            'type' => TypeNotification::class,
            'lue' => 'boolean',
            'date_envoi' => 'datetime',
            'date_lecture' => 'datetime',
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }
}
