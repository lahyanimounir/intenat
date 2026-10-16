<?php

namespace App\Http\Resources;

use App\Models\Utilisateur;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Utilisateur */
class UtilisateurResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = $this->roleCode();

        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'nom_complet' => $this->nomComplet(),
            'email' => $this->email,
            'actif' => $this->actif,
            'role' => $role ? ['valeur' => $role->value, 'libelle' => $role->libelle()] : null,
            'perimetre' => [
                'internat' => $this->whenLoaded('internat', fn () => $this->internat?->only(['id', 'nom', 'genre'])),
                'etablissement' => $this->whenLoaded('etablissement', fn () => $this->etablissement?->only(['id', 'code_efp', 'nom_efp'])),
                'complexe' => $this->whenLoaded('complexe', fn () => $this->complexe?->only(['id', 'code', 'nom'])),
                'region' => $this->whenLoaded('region', fn () => $this->region?->only(['id', 'code', 'nom'])),
            ],
            'internat_id' => $this->internat_id,
            'etablissement_id' => $this->etablissement_id,
            'complexe_id' => $this->complexe_id,
            'region_id' => $this->region_id,
            'derniere_connexion_le' => $this->derniere_connexion_le,
            'cree_le' => $this->created_at,
        ];
    }
}
