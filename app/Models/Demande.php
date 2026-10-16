<?php

namespace App\Models;

use App\Enums\ListeAdmission;
use App\Enums\StatutDemande;
use App\Models\Concerns\LimiteAuPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Dossier de préinscription : relie un stagiaire à une campagne et porte son
 * statut, son score, son classement et ses échéances.
 */
class Demande extends Model
{
    use HasFactory, LimiteAuPerimetre;

    protected $table = 'demande';

    protected $fillable = [
        'campagne_id', 'stagiaire_id', 'numero_dossier', 'statut', 'date_soumission', 'adresse_complete',
        'eloigne_ou_rural', 'demuni', 'orphelin_ou_divorce', 'besoins_specifiques', 'precisions_besoins',
        'score', 'rang', 'liste', 'motif_rejet', 'motif_rectification', 'date_confirmation',
        'date_limite_confirmation', 'date_limite_dossier_physique', 'dossier_physique_depose',
        'date_depot_physique', 'dossier_physique_valide_par', 'instruit_par', 'date_instruction',
        'version_grille', 'date_calcul_score', 'occupation_autorisee_le', 'occupation_autorisee_par',
        'occupation_sans_recu',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutDemande::class,
            'liste' => ListeAdmission::class,
            'date_soumission' => 'datetime',
            'eloigne_ou_rural' => 'boolean',
            'demuni' => 'boolean',
            'orphelin_ou_divorce' => 'boolean',
            'besoins_specifiques' => 'boolean',
            'score' => 'integer',
            'rang' => 'integer',
            'date_confirmation' => 'datetime',
            'date_limite_confirmation' => 'datetime',
            'date_limite_dossier_physique' => 'date',
            'dossier_physique_depose' => 'boolean',
            'date_depot_physique' => 'datetime',
            'date_instruction' => 'datetime',
            'version_grille' => 'integer',
            'date_calcul_score' => 'datetime',
            'occupation_autorisee_le' => 'datetime',
            'occupation_sans_recu' => 'boolean',
        ];
    }

    public static function cheminInternat(): string
    {
        return 'campagne';
    }

    public function campagne(): BelongsTo
    {
        return $this->belongsTo(Campagne::class, 'campagne_id');
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }

    public function instructeur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'instruit_par');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(PieceJointe::class, 'demande_id');
    }

    public function detailScore(): HasMany
    {
        return $this->hasMany(DemandeCritere::class, 'demande_id');
    }

    public function historique(): HasMany
    {
        return $this->hasMany(HistoriqueDemande::class, 'demande_id')->orderByDesc('date_heure')->orderByDesc('id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(DecisionAdmission::class, 'demande_id')->orderByDesc('date_validation');
    }

    public function desistement(): HasOne
    {
        return $this->hasOne(Desistement::class, 'demande_id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class, 'demande_id')->orderBy('periode');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class, 'demande_id')->orderByDesc('date_debut');
    }

    public function affectationActive(): HasOne
    {
        return $this->hasOne(Affectation::class, 'demande_id')->whereNull('date_fin');
    }

    /** Le stagiaire peut encore modifier son dossier et ses pièces. */
    public function estModifiableParLeStagiaire(): bool
    {
        return in_array($this->statut, [StatutDemande::Brouillon, StatutDemande::RetourRectification], true);
    }

    public function occupationAutorisee(): bool
    {
        return $this->occupation_autorisee_le !== null;
    }
}
