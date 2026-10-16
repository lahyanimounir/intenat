<?php

namespace App\Http\Resources;

use App\Models\Campagne;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Campagne */
class CampagneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'annee' => $this->annee,
            'internat' => $this->whenLoaded('internat', fn () => [
                'id' => $this->internat->id,
                'nom' => $this->internat->nom,
                'genre' => ['valeur' => $this->internat->genre->value, 'libelle' => $this->internat->genre->libelle()],
                'etablissement' => $this->internat->relationLoaded('etablissement')
                    ? $this->internat->etablissement->only(['id', 'code_efp', 'nom_efp']) : null,
            ]),
            'internat_id' => $this->internat_id,
            'date_ouverture' => $this->date_ouverture->toDateString(),
            'date_cloture' => $this->date_cloture->toDateString(),
            'nombre_places' => $this->nombre_places,
            'frais_mensuels' => $this->frais_mensuels,
            'statut' => ['valeur' => $this->statut->value, 'libelle' => $this->statut->libelle()],
            'phase' => $this->phase(),
            'version_grille' => $this->version_grille,
            'date_deliberation' => $this->date_deliberation,
            'date_publication' => $this->date_publication,
            'archivee_le' => $this->archivee_le,
            'dossiers' => $this->whenCounted('demandes'),
            'createur' => $this->whenLoaded('createur', fn () => $this->createur->nomComplet()),
            'cree_le' => $this->created_at,
        ];
    }
}
