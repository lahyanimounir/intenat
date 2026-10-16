<?php

namespace App\Http\Requests;

use App\Enums\RoleCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Création ou modification d'un compte du personnel. Le périmètre exigé
 * dépend du rôle ; les niveaux étrangers au rôle sont remis à vide.
 */
class EnregistrerUtilisateurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('utilisateurs.gerer') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $compte = $this->route('utilisateur');
        $role = RoleCode::tryFrom((string) $this->input('role'));

        return [
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('utilisateur', 'email')->ignore($compte?->id)],
            'role' => ['required', Rule::enum(RoleCode::class)],
            'actif' => ['sometimes', 'boolean'],
            'mot_de_passe' => [$compte ? 'nullable' : 'required', Password::defaults()],
            'internat_id' => [
                Rule::requiredIf($role === RoleCode::Gestionnaire), 'nullable', 'integer', 'exists:internat,id',
            ],
            'etablissement_id' => [
                Rule::requiredIf(in_array($role, [RoleCode::ChefPole, RoleCode::Restauration], true)
                    || ($role === RoleCode::Directeur && ! $this->filled('complexe_id'))),
                'nullable', 'integer', 'exists:etablissement,id',
            ],
            'complexe_id' => ['nullable', 'integer', 'exists:complexe,id'],
            'region_id' => [
                Rule::requiredIf($role === RoleCode::DirRegionale), 'nullable', 'integer', 'exists:region,id',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'internat_id' => 'internat', 'etablissement_id' => 'établissement',
            'complexe_id' => 'complexe', 'region_id' => 'région', 'mot_de_passe' => 'mot de passe',
        ];
    }

    /**
     * Colonnes de périmètre conservées pour le rôle choisi.
     *
     * @return array<string, int|null>
     */
    public function perimetre(): array
    {
        $role = RoleCode::from($this->validated('role'));

        $conserves = match ($role) {
            RoleCode::Gestionnaire => ['internat_id'],
            RoleCode::ChefPole, RoleCode::Restauration => ['etablissement_id'],
            RoleCode::Directeur => $this->filled('complexe_id') ? ['complexe_id'] : ['etablissement_id'],
            RoleCode::DirRegionale => ['region_id'],
            default => [],
        };

        $perimetre = ['internat_id' => null, 'etablissement_id' => null, 'complexe_id' => null, 'region_id' => null];

        foreach ($conserves as $colonne) {
            $perimetre[$colonne] = (int) $this->validated($colonne);
        }

        return $perimetre;
    }
}
