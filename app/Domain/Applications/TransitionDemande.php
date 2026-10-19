<?php

namespace App\Domain\Applications;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutDemande;
use App\Models\Demande;
use App\Models\HistoriqueDemande;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\Auth;

/**
 * Machine à états du dossier. Toute modification de `demande.statut` passe
 * par ce service : il refuse les transitions non prévues et consigne chaque
 * changement (auteur, date, motif, ancien et nouveau statut).
 */
class TransitionDemande
{
    /** @var array<string, list<StatutDemande>> */
    private const TRANSITIONS = [
        'brouillon' => [StatutDemande::Recu],
        // Recu → Brouillon : le stagiaire retire sa candidature (non encore instruite) pour la modifier.
        'recu' => [StatutDemande::Complet, StatutDemande::RetourRectification, StatutDemande::Rejete, StatutDemande::Brouillon],
        'retour_rectification' => [StatutDemande::Recu, StatutDemande::Rejete],
        'complet' => [StatutDemande::Admis, StatutDemande::EnAttente, StatutDemande::Refuse],
        // Plus de délai de confirmation : un admis n'est jamais déchu faute de réponse.
        'admis' => [StatutDemande::Confirme, StatutDemande::Desiste],
        'en_attente' => [StatutDemande::Admis, StatutDemande::Refuse, StatutDemande::Desiste],
        'confirme' => [StatutDemande::Desiste, StatutDemande::SejourTermine],
        'rejete' => [],
        'refuse' => [],
        'desiste' => [],
        'delai_depasse' => [],
        'sejour_termine' => [],
    ];

    public function __construct(private readonly Journal $journal) {}

    public function estPermise(StatutDemande $depuis, StatutDemande $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$depuis->value] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributs  Autres colonnes à mettre à jour dans la même écriture.
     */
    public function appliquer(
        Demande $demande,
        StatutDemande $vers,
        ?string $motif = null,
        array $attributs = [],
        bool $automatique = false,
    ): Demande {
        $depuis = $demande->statut;

        if (! $this->estPermise($depuis, $vers)) {
            throw new RegleMetierException(
                "Transition impossible : un dossier « {$depuis->libelle()} » ne peut pas devenir « {$vers->libelle()} ».",
                'transition_interdite',
            );
        }

        return $this->ecrire($demande, $vers, $motif, $attributs, $automatique);
    }

    /**
     * Seul retour arrière admis, et ce n'est pas une transition ordinaire : un dossier « Complet » repasse
     * « Reçu » quand l'inscription en formation du stagiaire est remise en cause avant la délibération
     * (voir ControleInscription). Il quitte ainsi la sélection et doit être instruit de nouveau.
     */
    public function rouvrirInstruction(Demande $demande, string $motif): Demande
    {
        if ($demande->statut !== StatutDemande::Complet) {
            throw new RegleMetierException('Seul un dossier complet peut être remis en instruction.', 'transition_interdite');
        }

        return $this->ecrire($demande, StatutDemande::Recu, $motif, ['score' => null, 'rang' => null, 'liste' => null], false);
    }

    /** @param  array<string, mixed>  $attributs */
    private function ecrire(Demande $demande, StatutDemande $vers, ?string $motif, array $attributs, bool $automatique): Demande
    {
        $depuis = $demande->statut;

        $demande->forceFill($attributs + ['statut' => $vers])->save();

        $acteur = $automatique ? null : (Auth::guard('web')->user() ?? Auth::guard('stagiaire')->user());

        HistoriqueDemande::create([
            'demande_id' => $demande->id,
            'ancien_statut' => $depuis->value,
            'nouveau_statut' => $vers->value,
            'utilisateur_id' => $acteur instanceof Utilisateur ? $acteur->id : null,
            'stagiaire_id' => $acteur instanceof Stagiaire ? $acteur->id : null,
            'automatique' => $automatique,
            'motif' => $motif,
            'date_heure' => now(),
        ]);

        $this->journal->consigner('demande.statut', $demande, details: array_filter([
            'avant' => $depuis->value,
            'apres' => $vers->value,
            'motif' => $motif,
            'automatique' => $automatique ?: null,
        ], fn ($v) => $v !== null));

        return $demande;
    }
}
