<?php

namespace App\Models;

use App\Enums\StatutCampagne;
use App\Enums\StatutDemande;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Période pendant laquelle les stagiaires d'un internat peuvent demander une place.
 *
 * Statuts documentés : Préparée → Ouverte → Clôturée. Les phases suivantes
 * (délibérée, archivée) sont portées par des dates, sans statut supplémentaire.
 */
class Campagne extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'campagne';

    protected $fillable = [
        'internat_id', 'annee', 'date_ouverture', 'date_cloture', 'nombre_places', 'statut', 'cree_par',
        'frais', 'version_grille', 'compteur_dossiers', 'date_deliberation', 'delibere_par',
        'date_publication', 'message_deliberation', 'archivee_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutCampagne::class,
            'date_ouverture' => 'date',
            'date_cloture' => 'date',
            'nombre_places' => 'integer',
            'frais' => 'decimal:2',
            'version_grille' => 'integer',
            'compteur_dossiers' => 'integer',
            'date_deliberation' => 'datetime',
            'date_publication' => 'datetime',
            'archivee_le' => 'datetime',
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

    public function createur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    public function typesPieces(): HasMany
    {
        return $this->hasMany(TypePiece::class, 'campagne_id');
    }

    public function criteres(): HasMany
    {
        return $this->hasMany(Critere::class, 'campagne_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class, 'campagne_id');
    }

    /**
     * Campagnes qui reçoivent les candidatures : ouvertes par le chef de pôle et non échues. Ouvrir la
     * campagne la rend accessible aux candidats, y compris avant sa date officielle d'ouverture.
     */
    public function scopeAccessibles(Builder $requete): Builder
    {
        return $requete->where($requete->qualifyColumn('statut'), StatutCampagne::Ouverte->value)
            ->whereDate($requete->qualifyColumn('date_cloture'), '>=', now()->toDateString());
    }

    /** Le dépôt est possible : campagne ouverte par le chef de pôle, date de clôture non passée. */
    public function accepteLesDepots(): bool
    {
        return $this->statut === StatutCampagne::Ouverte
            && now()->startOfDay()->lessThanOrEqualTo($this->date_cloture);
    }

    /** Campagne déjà accessible aux candidats, avant sa date officielle d'ouverture. */
    public function avantOuverture(): bool
    {
        return now()->startOfDay()->lessThan($this->date_ouverture);
    }

    /** Désignation proposée au candidat : « Campagne 2026-2027 — du 09/11/2026 au 07/01/2027 ». */
    public function libelleCandidature(): string
    {
        return 'Campagne '.$this->annee.' — du '.$this->date_ouverture->format('d/m/Y').' au '.$this->date_cloture->format('d/m/Y');
    }

    /** Désignation courte, qui distingue les campagnes d'une même année : « 2026-2027 · du 01/09 au 15/09/2026 ». */
    public function libellePeriode(): string
    {
        return $this->annee.' · du '.$this->date_ouverture->format('d/m').' au '.$this->date_cloture->format('d/m/Y');
    }

    public function estDeliberee(): bool
    {
        return $this->date_deliberation !== null;
    }

    public function estArchivee(): bool
    {
        return $this->archivee_le !== null;
    }

    /** Phase affichée : préparation, dépôt, instruction, délibérée, archivée. */
    public function phase(): string
    {
        return match (true) {
            $this->estArchivee() => 'archivee',
            $this->estDeliberee() => 'deliberee',
            $this->statut === StatutCampagne::Cloturee => 'instruction',
            $this->statut === StatutCampagne::Ouverte => 'depot',
            default => 'preparation',
        };
    }

    /** Places de la liste principale actuellement tenues par un admis ou un confirmé. */
    public function placesOccupees(): int
    {
        return $this->demandes()
            ->whereIn('statut', [StatutDemande::Admis->value, StatutDemande::Confirme->value])
            ->count();
    }

    public function placesLibres(): int
    {
        return max(0, $this->nombre_places - $this->placesOccupees());
    }
}
