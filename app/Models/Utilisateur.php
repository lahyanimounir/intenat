<?php

namespace App\Models;

use App\Enums\RoleCode;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte du personnel. Les stagiaires n'y figurent pas (voir Stagiaire).
 *
 * Le rôle est porté par Spatie Laravel Permission ; le périmètre par les
 * colonnes internat_id / etablissement_id / complexe_id / region_id.
 */
class Utilisateur extends Authenticatable
{
    use HasFactory, HasRoles, RoutesNotifications;

    protected $table = 'utilisateur';

    protected string $guard_name = 'web';

    protected $fillable = [
        'internat_id', 'etablissement_id', 'complexe_id', 'region_id',
        'nom', 'prenom', 'email', 'mot_de_passe_hash', 'actif', 'derniere_connexion_le',
    ];

    protected $hidden = ['mot_de_passe_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'mot_de_passe_hash' => 'hashed',
            'mot_de_passe_provisoire' => 'boolean',
            'actif' => 'boolean',
            'derniere_connexion_le' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe_hash';
    }

    /** Lien de réinitialisation envoyé à l'adresse du compte. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ReinitialisationMotDePasse($token, 'personnel'));
    }

    public function internat(): BelongsTo
    {
        return $this->belongsTo(Internat::class, 'internat_id');
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }

    public function complexe(): BelongsTo
    {
        return $this->belongsTo(Complexe::class, 'complexe_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    /** Un compte porte exactement un rôle institutionnel. */
    public function roleCode(): ?RoleCode
    {
        $nom = $this->roles->first()?->name;

        return $nom ? RoleCode::tryFrom($nom) : null;
    }

    public function aLeRole(RoleCode ...$roles): bool
    {
        return in_array($this->roleCode(), $roles, true);
    }

    public function nomComplet(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }
}
