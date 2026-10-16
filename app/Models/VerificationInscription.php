<?php

namespace App\Models;

use App\Enums\OrigineVerificationInscription;
use App\Enums\StatutVerificationInscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une soumission de l'inscription en formation à la vérification, ou une
 * décision de l'administration : ce qui a été déclaré, le justificatif, puis
 * l'agent, la date et le motif de la décision. Les lignes ne sont jamais
 * supprimées ni réécrites après décision : elles forment l'historique.
 */
class VerificationInscription extends Model
{
    protected $table = 'verification_inscription';

    protected $fillable = [
        'stagiaire_id', 'origine', 'statut', 'cef', 'etablissement_id', 'nom_filiere', 'niveau_formation',
        'annee_formation', 'annee_scolaire', 'fichier', 'nom_original', 'mime', 'taille',
        'soumise_le', 'decidee_le', 'decidee_par', 'motif',
    ];

    // Le chemin du justificatif ne sort jamais dans une réponse : le fichier est servi après contrôle d'accès.
    protected $hidden = ['fichier'];

    protected function casts(): array
    {
        return [
            'origine' => OrigineVerificationInscription::class,
            'statut' => StatutVerificationInscription::class,
            'soumise_le' => 'datetime',
            'decidee_le' => 'datetime',
        ];
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'decidee_par');
    }

    public function scopeEnAttente(Builder $requete): Builder
    {
        return $requete->where('statut', StatutVerificationInscription::Soumise->value);
    }

    /** Inscriptions reprises de l'ancien parcours MyWay, qu'aucun agent n'a encore confirmées. */
    public function scopeReprisesAReexaminer(Builder $requete): Builder
    {
        return $requete
            ->where('origine', OrigineVerificationInscription::Reprise->value)
            ->where('statut', StatutVerificationInscription::Verifiee->value)
            ->whereNull('decidee_par');
    }

    public function aUnJustificatif(): bool
    {
        return $this->fichier !== null;
    }
}
