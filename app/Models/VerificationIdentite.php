<?php

namespace App\Models;

use App\Enums\MethodeVerification;
use App\Enums\ResultatVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vérification d'identité d'un stagiaire auprès du fournisseur (MyWay).
 *
 * Une vérification réussie porte l'identité établie, la candidature éligible
 * retenue et une autorisation d'activation à usage unique. Elle n'est pas un
 * compte : la ligne `stagiaire` n'existe qu'une fois le mot de passe INTERNAT
 * choisi. Une vérification non aboutie est gardée pour examen par
 * l'administration, sans profil.
 *
 * @property array{nom: string, prenom: string, cni: ?string, tel: ?string, ville: ?string, origines: array<string, string>, provenance?: array<string, string>}|null $profil
 */
class VerificationIdentite extends Model
{
    protected $table = 'verification_identite';

    protected $fillable = [
        'fournisseur', 'methode', 'simulee', 'resultat', 'cef', 'email', 'profil', 'etablissement_id', 'nom_efp',
        'nom_filiere', 'statut_candidature', 'annee_formation', 'champs_manquants', 'jeton_hash', 'expire_le',
        'consommee_le', 'stagiaire_id', 'examinee_le', 'examinee_par', 'note_examen', 'adresse_ip',
    ];

    protected $hidden = ['jeton_hash', 'profil'];

    protected function casts(): array
    {
        return [
            'methode' => MethodeVerification::class,
            'resultat' => ResultatVerification::class,
            'simulee' => 'boolean',
            // Chiffré avec la clé de l'application : illisible depuis une sauvegarde ou un accès direct à la base.
            'profil' => 'encrypted:array',
            'champs_manquants' => 'array',
            'expire_le' => 'datetime',
            'consommee_le' => 'datetime',
            'examinee_le' => 'datetime',
        ];
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }

    public function stagiaire(): BelongsTo
    {
        return $this->belongsTo(Stagiaire::class, 'stagiaire_id');
    }

    public function examinateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'examinee_par');
    }

    /** Vérifications réussies dont l'autorisation d'activation peut encore servir. */
    public function scopeEnCours(Builder $requete): Builder
    {
        return $requete
            ->where('resultat', ResultatVerification::Verifiee->value)
            ->whereNull('consommee_le')
            ->where('expire_le', '>', now());
    }

    /** Vérifications non abouties que l'administration n'a pas encore examinées. */
    public function scopeAExaminer(Builder $requete): Builder
    {
        return $requete->where('resultat', '!=', ResultatVerification::Verifiee->value)->whereNull('examinee_le');
    }

    public function estUtilisable(): bool
    {
        return $this->resultat === ResultatVerification::Verifiee
            && $this->consommee_le === null
            && $this->expire_le !== null
            && $this->expire_le->isFuture();
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }
}
