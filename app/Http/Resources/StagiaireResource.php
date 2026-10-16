<?php

namespace App\Http\Resources;

use App\Models\Stagiaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Stagiaire */
class StagiaireResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cef' => $this->cef,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'nom_complet' => $this->nomComplet(),
            'email' => $this->email,
            'cni' => $this->cni,
            'tel' => $this->tel,
            'ville' => $this->ville,
            'nom_filiere' => $this->nom_filiere,
            'niveau_formation' => $this->niveau_formation
                ? ['valeur' => $this->niveau_formation->value, 'libelle' => $this->niveau_formation->libelle()] : null,
            'genre' => $this->genre ? ['valeur' => $this->genre->value, 'libelle' => $this->genre->libelle()] : null,
            'etablissement' => $this->whenLoaded('etablissement', fn () => $this->etablissement->only(['id', 'code_efp', 'nom_efp'])),
            'date_import_myway' => $this->date_import_myway,
            // `mock` : profil fictif du simulateur ; `connecteur` / `approuve` : lu sur MyWay ; null : ligne de démonstration.
            'source_identite' => $this->source_identite,
            // Statut de l'inscription à la formation au moment de la vérification — sans rapport avec le paiement de l'hébergement.
            'statut_myway' => $this->statut_myway,
            'annee_formation' => $this->annee_formation,
            // Origine de chaque information : importe, correspondance, manquant ou declare.
            'origine_champs' => $this->origine_champs ?? (object) [],
            'etat_compte' => ['valeur' => $this->etat_compte->value, 'libelle' => $this->etat_compte->libelle()],
            'compte_active_le' => $this->compte_active_le,
        ];
    }
}
