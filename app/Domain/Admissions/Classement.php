<?php

namespace App\Domain\Admissions;

use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\ListeAdmission;
use App\Enums\StatutDemande;
use App\Enums\StatutInscription;
use App\Models\Campagne;
use App\Models\Demande;
use Illuminate\Support\Facades\DB;

/**
 * Classement des dossiers complets d'une campagne et préparation des listes.
 *
 * Une campagne appartient à un internat, donc à un genre : classer les
 * campagnes filles et garçons d'un établissement donne les quatre listes
 * (admis filles, admis garçons, attente filles, attente garçons).
 *
 * Départage — la documentation ne fixe aucune règle. Par défaut, les dossiers
 * de même score partagent le même rang ; si l'égalité chevauche la limite des
 * places, le classement le signale et le chef de pôle doit arbitrer. Aucun
 * attribut personnel (genre, âge, handicap, situation familiale) n'est utilisé.
 * L'ordre d'affichage entre ex æquo (date de dépôt) est purement technique.
 */
class Classement
{
    public function __construct(private readonly Parametres $parametres) {}

    /**
     * Actualise le classement d'une campagne dès qu'un dossier y entre ou en sort (validation,
     * annulation, inscription remise en cause). Le calcul est immédiat, dans la même transaction :
     * il ne dépend ni d'une file d'attente, ni d'un clic sur « Recalculer ». Une campagne délibérée
     * garde son classement figé.
     */
    public function actualiser(int $campagneId): void
    {
        $campagne = Campagne::find($campagneId);

        if ($campagne !== null && ! $campagne->estDeliberee()) {
            $this->recalculer($campagne);
        }
    }

    /** Un dossier complet attend encore son rang : le classement n'est plus à jour (rattrapage). */
    public function enRetard(Campagne $campagne): bool
    {
        return ! $campagne->estDeliberee() && Demande::where('campagne_id', $campagne->id)
            ->where('statut', StatutDemande::Complet->value)
            ->whereHas('stagiaire', fn ($q) => $q->where('statut_inscription', StatutInscription::Verifiee->value))
            ->whereNull('rang')->exists();
    }

    /**
     * @return array{classes: int, places: int, ex_aequo_a_la_limite: bool, score_limite: int|null}
     */
    public function recalculer(Campagne $campagne): array
    {
        if ($campagne->estDeliberee()) {
            throw new RegleMetierException(
                'Les admissions de cette campagne sont validées : le classement est figé.',
                'campagne_deliberee',
            );
        }

        $departageParDate = $this->parametres->texte('classement.departage') === 'date_soumission';

        return DB::transaction(function () use ($campagne, $departageParDate) {
            $dossiers = Demande::query()
                ->where('campagne_id', $campagne->id)
                ->where('statut', StatutDemande::Complet->value)
                // Garde-fou : un dossier ne se classe que si l'inscription en formation du stagiaire est vérifiée.
                ->whereHas('stagiaire', fn ($q) => $q->where('statut_inscription', StatutInscription::Verifiee->value))
                ->orderByDesc('score')
                ->orderBy('date_soumission')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'score', 'rang', 'liste']);

            $places = $campagne->nombre_places;
            $rang = 0;
            $scorePrecedent = null;

            foreach ($dossiers as $position => $dossier) {
                if ($departageParDate || $dossier->score !== $scorePrecedent) {
                    $rang = $position + 1;
                }
                $scorePrecedent = $dossier->score;
                $liste = $position < $places ? ListeAdmission::Principale : ListeAdmission::Attente;

                if ($dossier->rang !== $rang || $dossier->liste !== $liste) {
                    Demande::whereKey($dossier->id)->update(['rang' => $rang, 'liste' => $liste->value]);
                }
            }

            // Avant délibération, seul un dossier « Complet » figure au classement.
            Demande::where('campagne_id', $campagne->id)
                ->where('statut', '!=', StatutDemande::Complet->value)
                ->where(fn ($q) => $q->whereNotNull('rang')->orWhereNotNull('liste'))
                ->update(['rang' => null, 'liste' => null]);

            $dernierAdmis = $dossiers->get($places - 1);
            $premierEnAttente = $dossiers->get($places);
            $exAequo = ! $departageParDate
                && $dernierAdmis !== null
                && $premierEnAttente !== null
                && $dernierAdmis->score === $premierEnAttente->score;

            return [
                'classes' => $dossiers->count(),
                'places' => $places,
                'ex_aequo_a_la_limite' => $exAequo,
                'score_limite' => $dernierAdmis?->score,
            ];
        });
    }
}
