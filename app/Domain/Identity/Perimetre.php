<?php

namespace App\Domain\Identity;

use App\Enums\RoleCode;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Périmètre organisationnel d'un compte du personnel.
 *
 * Un rôle n'ouvre jamais l'accès à toutes les lignes : chaque lecture, écriture,
 * statistique ou export est restreint aux internats que le compte a en charge.
 * Ce service est le point unique de calcul de ce périmètre.
 *
 *   Administrateur, Direction centrale ......... tous les internats
 *   Direction régionale ......................... internats de sa région
 *   Directeur de complexe / CMC ................. internats de son complexe (ou de son établissement)
 *   Chef de pôle, Responsable restauration ...... internats de son établissement
 *   Gestionnaire ................................ son internat uniquement
 */
class Perimetre
{
    /** @var array<int, list<int>|null> */
    private array $memo = [];

    /**
     * Identifiants des internats accessibles, ou null si aucune restriction.
     *
     * @return list<int>|null
     */
    public function internatIds(Utilisateur $utilisateur): ?array
    {
        if (array_key_exists($utilisateur->id, $this->memo)) {
            return $this->memo[$utilisateur->id];
        }

        return $this->memo[$utilisateur->id] = $this->calculer($utilisateur);
    }

    /** @return list<int>|null */
    public function etablissementIds(Utilisateur $utilisateur): ?array
    {
        $internats = $this->internatIds($utilisateur);

        if ($internats === null) {
            return null;
        }

        return Internat::query()->whereIn('id', $internats)->distinct()->pluck('etablissement_id')->all();
    }

    public function couvreInternat(Utilisateur $utilisateur, ?int $internatId): bool
    {
        if ($internatId === null) {
            return false;
        }

        $ids = $this->internatIds($utilisateur);

        return $ids === null || in_array($internatId, $ids, true);
    }

    /** @throws AuthorizationException */
    public function exigerInternat(Utilisateur $utilisateur, ?int $internatId): void
    {
        if (! $this->couvreInternat($utilisateur, $internatId)) {
            throw new AuthorizationException('Cette ressource est hors de votre périmètre.');
        }
    }

    /** Restreint une requête dont la table porte directement `internat_id`. */
    public function restreindre(Builder $requete, Utilisateur $utilisateur, string $colonne = 'internat_id'): Builder
    {
        $ids = $this->internatIds($utilisateur);

        return $ids === null ? $requete : $requete->whereIn($requete->qualifyColumn($colonne), $ids);
    }

    /**
     * Un stagiaire relève d'un compte par son établissement. Le gestionnaire, attaché à un seul
     * internat, ne couvre en outre que les stagiaires du genre que son internat accueille.
     */
    public function couvreStagiaire(Utilisateur $utilisateur, Stagiaire $stagiaire): bool
    {
        $ids = $this->internatIds($utilisateur);

        if ($ids === null) {
            return true;
        }

        if ($stagiaire->etablissement_id === null) {
            return false;
        }

        return Internat::query()
            ->whereIn('id', $ids)
            ->where('etablissement_id', $stagiaire->etablissement_id)
            ->when($utilisateur->aLeRole(RoleCode::Gestionnaire), fn (Builder $q) => $q->where('genre', $stagiaire->genre?->value))
            ->exists();
    }

    /** Restreint une requête sur `stagiaire` (ou portant `etablissement_id` et `genre`) au périmètre du compte. */
    public function restreindreStagiaires(Builder $requete, Utilisateur $utilisateur, string $table = 'stagiaire'): Builder
    {
        $ids = $this->internatIds($utilisateur);

        if ($ids === null) {
            return $requete;
        }

        $parGenre = $utilisateur->aLeRole(RoleCode::Gestionnaire);

        return $requete->whereExists(fn ($internats) => $internats
            ->selectRaw('1')
            ->from('internat')
            ->whereIn('internat.id', $ids)
            ->whereColumn('internat.etablissement_id', "{$table}.etablissement_id")
            ->when($parGenre, fn ($q) => $q->whereColumn('internat.genre', "{$table}.genre")));
    }

    public function oublier(): void
    {
        $this->memo = [];
    }

    /** @return list<int>|null */
    private function calculer(Utilisateur $utilisateur): ?array
    {
        if (! $utilisateur->actif) {
            return [];
        }

        return match ($utilisateur->roleCode()) {
            RoleCode::Admin, RoleCode::DirCentrale => null,
            RoleCode::DirRegionale => $this->internatsDesEtablissements(
                $utilisateur->region_id
                    ? Etablissement::whereHas('complexe.province', fn ($q) => $q->where('region_id', $utilisateur->region_id))
                    : null
            ),
            RoleCode::Directeur => $this->internatsDesEtablissements(match (true) {
                $utilisateur->complexe_id !== null => Etablissement::where('complexe_id', $utilisateur->complexe_id),
                $utilisateur->etablissement_id !== null => Etablissement::whereKey($utilisateur->etablissement_id),
                default => null,
            }),
            RoleCode::ChefPole, RoleCode::Restauration => $this->internatsDesEtablissements(
                $utilisateur->etablissement_id ? Etablissement::whereKey($utilisateur->etablissement_id) : null
            ),
            RoleCode::Gestionnaire => $utilisateur->internat_id ? [$utilisateur->internat_id] : [],
            default => [],
        };
    }

    /** @return list<int> */
    private function internatsDesEtablissements(?Builder $etablissements): array
    {
        if ($etablissements === null) {
            return [];
        }

        return Internat::query()
            ->whereIn('etablissement_id', $etablissements->select('id'))
            ->pluck('id')
            ->all();
    }
}
