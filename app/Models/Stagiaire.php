<?php

namespace App\Models;

use App\Enums\AnneeFormation;
use App\Enums\EtatCompte;
use App\Enums\Genre;
use App\Enums\NiveauFormation;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Notifications\ReinitialisationMotDePasse;
use App\Notifications\VerificationAdresse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;

/**
 * Compte INTERNAT d'un stagiaire.
 *
 * Le stagiaire crée lui-même son compte (adresse et mot de passe), puis déclare
 * son identité et sa formation : ces colonnes sont donc vides tant qu'il ne les
 * a pas renseignées. Ce qu'il déclare ne vaut pas preuve — son inscription en
 * formation est vérifiée par un agent habilité (voir VerificationInscription),
 * et seul `statut_inscription = verifiee` ouvre la sélection.
 *
 * `source_identite`, `statut_myway` et `date_import_myway` datent de l'ancien
 * parcours MyWay : ils sont conservés comme historique et ne sont plus écrits.
 *
 * @property array<string, string>|null $origine_champs Origine de chaque information (voir OrigineChamp).
 */
class Stagiaire extends Authenticatable
{
    use HasFactory, RoutesNotifications;

    protected $table = 'stagiaire';

    protected $fillable = [
        'etablissement_id', 'cef', 'nom', 'prenom', 'email', 'mot_de_passe_hash', 'cni', 'tel', 'ville', 'adresse',
        'nom_filiere', 'source_identite', 'statut_myway', 'annee_formation', 'annee_scolaire', 'origine_champs', 'niveau_formation', 'genre',
        'date_import_myway', 'inscrit_le', 'email_verifie_le', 'profil_complete_le', 'statut_inscription', 'inscription_verifiee_le',
        'compte_active_le', 'etat_compte', 'suspendu_le', 'motif_suspension', 'mot_de_passe_modifie_le', 'derniere_connexion_le',
    ];

    protected $hidden = ['mot_de_passe_hash', 'remember_token'];

    /** Ce que le stagiaire doit avoir déclaré avant de soumettre son inscription ou d'ouvrir un dossier. La CNI reste facultative. */
    public const CHAMPS_DU_PROFIL = [
        'cef', 'nom', 'prenom', 'genre', 'tel', 'ville', 'adresse',
        'etablissement_id', 'nom_filiere', 'niveau_formation', 'annee_formation', 'annee_scolaire',
    ];

    /** Ce qui identifie le stagiaire et sa formation : figé dès la soumission, et après vérification. */
    public const CHAMPS_VERIFIES = [
        'cef', 'nom', 'prenom', 'cni', 'etablissement_id', 'nom_filiere', 'niveau_formation', 'annee_formation', 'annee_scolaire',
    ];

    protected function casts(): array
    {
        return [
            'mot_de_passe_hash' => 'hashed',
            'genre' => Genre::class,
            'niveau_formation' => NiveauFormation::class,
            'annee_formation' => AnneeFormation::class,
            'statut_inscription' => StatutInscription::class,
            'date_import_myway' => 'datetime',
            'inscrit_le' => 'datetime',
            'email_verifie_le' => 'datetime',
            'profil_complete_le' => 'datetime',
            'inscription_verifiee_le' => 'datetime',
            'compte_active_le' => 'datetime',
            'etat_compte' => EtatCompte::class,
            'origine_champs' => 'array',
            'suspendu_le' => 'datetime',
            'mot_de_passe_modifie_le' => 'datetime',
            'derniere_connexion_le' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe_hash';
    }

    /** Seul un compte actif ouvre une session : ni une ligne en attente, ni un compte suspendu. */
    public function estActif(): bool
    {
        return $this->etat_compte === EtatCompte::Actif;
    }

    /** Lien de réinitialisation envoyé à l'adresse du compte. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ReinitialisationMotDePasse($token, 'stagiaire'));
    }

    /** Identité et formation entièrement déclarées : condition pour soumettre son inscription et ouvrir un dossier. */
    public function profilComplet(): bool
    {
        $valeurs = $this->getAttributes();

        foreach (self::CHAMPS_DU_PROFIL as $champ) {
            if (blank($valeurs[$champ] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** L'identité déclarée : première étape du profil. */
    public function identiteDeclaree(): bool
    {
        return filled($this->cef) && filled($this->nom) && filled($this->prenom) && $this->genre !== null;
    }

    /** L'inscription en formation a été vérifiée par un agent habilité. */
    public function inscriptionVerifiee(): bool
    {
        return $this->statut_inscription === StatutInscription::Verifiee;
    }

    /* ------------------------------------------------- Vérification de l'adresse */

    /** L'adresse n'est vérifiée que si un serveur d'envoi réel est configuré : sans lui, aucun lien ne partirait. */
    public static function verificationAdresseExigee(): bool
    {
        $reglage = config('internat.inscription.verifier_adresse');

        return $reglage === null
            ? ! in_array(config('mail.default'), ['log', 'array', null], true)
            : (bool) $reglage;
    }

    public function adresseVerifiee(): bool
    {
        return $this->email_verifie_le !== null;
    }

    /** Vrai si le stagiaire doit encore confirmer son adresse avant d'aller plus loin. */
    public function doitVerifierSonAdresse(): bool
    {
        return self::verificationAdresseExigee() && ! $this->adresseVerifiee();
    }

    public function envoyerVerificationAdresse(): void
    {
        $this->notify(new VerificationAdresse);
    }

    /**
     * Consigne qu'une information vient du stagiaire lui-même et non du fournisseur d'identité.
     *
     * @param  list<string>  $champs
     */
    public function marquerDeclare(array $champs): void
    {
        $this->origine_champs = array_merge($this->origine_champs ?? [], array_fill_keys($champs, 'declare'));
    }

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class, 'stagiaire_id');
    }

    public function verificationsInscription(): HasMany
    {
        return $this->hasMany(VerificationInscription::class, 'stagiaire_id');
    }

    public function regimes(): HasMany
    {
        return $this->hasMany(RegimeAlimentaire::class, 'stagiaire_id');
    }

    public function reclamations(): HasMany
    {
        return $this->hasMany(Reclamation::class, 'stagiaire_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ReservationRepas::class, 'stagiaire_id');
    }

    /** Internat correspondant au genre déclaré, dans l'établissement du stagiaire. */
    public function internat(): ?Internat
    {
        if ($this->genre === null || $this->etablissement_id === null) {
            return null;
        }

        return Internat::query()
            ->where('etablissement_id', $this->etablissement_id)
            ->where('genre', $this->genre->value)
            ->first();
    }

    /** Dossier le plus récent du stagiaire. */
    public function demandeCourante(): ?Demande
    {
        return $this->demandes()->with('campagne.internat')->latest('id')->first();
    }

    /** Séjour en cours, s'il occupe actuellement un lit. */
    public function affectationActive(): ?Affectation
    {
        return Affectation::query()
            ->whereNull('date_fin')
            ->whereHas('demande', fn ($q) => $q->where('stagiaire_id', $this->id))
            ->with('lit.chambre.batiment.internat', 'demande')
            ->first();
    }

    /**
     * Un lit lui a été affecté (séjour en cours ou passé) : condition d'accès aux services du séjour.
     * Ni la validation du dossier, ni l'admission, ni la confirmation de la place n'y suffisent.
     */
    public function aUnLitAffecte(): bool
    {
        return Affectation::query()->whereHas('demande', fn ($q) => $q->where('stagiaire_id', $this->id))->exists();
    }

    /** Le genre devient définitif dès qu'un dossier a quitté l'état de brouillon. */
    public function genreVerrouille(): bool
    {
        return $this->demandes()->where('statut', '!=', StatutDemande::Brouillon->value)->exists();
    }

    public function nomComplet(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }
}
