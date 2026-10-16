<?php

namespace App\Http\Resources;

use App\Domain\Shared\Parametres;
use App\Models\Demande;
use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dossier de préinscription. Le score, le rang et la liste ne sont exposés
 * qu'aux titulaires de `admissions.consulter` (chef de pôle) : ils restent
 * masqués au gestionnaire pendant l'instruction, et le stagiaire ne reçoit
 * que sa décision (admis, liste d'attente, refusé) une fois publiée.
 *
 * @mixin Demande
 */
class DemandeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $lecteur = $request->user();

        return [
            'id' => $this->id,
            'numero_dossier' => $this->numero_dossier,
            'statut' => ['valeur' => $this->statut->value, 'libelle' => $this->statut->libelle()],
            'date_soumission' => $this->date_soumission,
            'adresse_complete' => $this->adresse_complete,
            'eloigne_ou_rural' => $this->eloigne_ou_rural,
            'demuni' => $this->demuni,
            'orphelin_ou_divorce' => $this->orphelin_ou_divorce,
            'besoins_specifiques' => $this->besoins_specifiques,
            'precisions_besoins' => $this->precisions_besoins,
            'motif_rejet' => $this->motif_rejet,
            'motif_rectification' => $this->motif_rectification,
            'date_instruction' => $this->date_instruction,
            'date_confirmation' => $this->date_confirmation,
            'dossier_physique_depose' => $this->dossier_physique_depose,
            'date_depot_physique' => $this->date_depot_physique,
            'occupation' => [
                'autorisee' => $this->occupationAutorisee(),
                'autorisee_le' => $this->occupation_autorisee_le,
                'sans_recu' => $this->occupation_sans_recu,
            ],
            'campagne_id' => $this->campagne_id,
            'campagne' => new CampagneResource($this->whenLoaded('campagne')),
            'stagiaire' => new StagiaireResource($this->whenLoaded('stagiaire')),
            'instructeur' => $this->whenLoaded('instructeur', fn () => $this->instructeur?->nomComplet()),
            $this->mergeWhen($this->peutVoirLeClassement($lecteur), fn () => [
                'score' => $this->score,
                'rang' => $this->rang,
                'liste' => $this->liste ? ['valeur' => $this->liste->value, 'libelle' => $this->liste->libelle()] : null,
                'version_grille' => $this->version_grille,
                'date_calcul_score' => $this->date_calcul_score,
            ]),
        ];
    }

    private function peutVoirLeClassement(mixed $lecteur): bool
    {
        if ($lecteur instanceof Utilisateur) {
            return $lecteur->can('admissions.consulter')
                || ($lecteur->can('demandes.instruire') && ! app(Parametres::class)->vrai('instruction.masquer_score_gestionnaire'));
        }

        // Le stagiaire ne voit ni son rang ni son score : seule sa décision lui est communiquée.
        return false;
    }
}
