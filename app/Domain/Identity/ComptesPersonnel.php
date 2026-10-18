<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Models\Internat;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Comptes du personnel : un rôle et un périmètre par compte. Le périmètre
 * exigé dépend du rôle ; les niveaux étrangers au rôle sont remis à vide.
 * Un compte ne se supprime pas : il se désactive, son historique reste
 * rattaché. Au moins un administrateur actif subsiste toujours, et nul ne
 * modifie son propre rôle ni ne désactive son propre compte.
 */
class ComptesPersonnel
{
    private const COLONNES_PERIMETRE = ['internat_id', 'etablissement_id', 'complexe_id', 'region_id'];

    /** Niveaux de la hiérarchie administrative, du plus large au plus fin. */
    public const NIVEAUX = ['region_id', 'province_id', 'complexe_id', 'etablissement_id', 'internat_id'];

    /**
     * Rôles rattachés en parcourant la hiérarchie : niveaux à choisir dans l'ordre, le dernier
     * étant le rattachement du compte. Les autres rôles gardent leur périmètre propre.
     */
    public const HIERARCHIE = [
        'GESTIONNAIRE' => ['region_id', 'province_id', 'complexe_id', 'etablissement_id', 'internat_id'],
        'RESTAURATION' => ['region_id', 'province_id', 'complexe_id', 'etablissement_id'],
        'CHEF_POLE' => ['region_id', 'province_id', 'complexe_id', 'etablissement_id'],
        'DIRECTEUR' => ['region_id', 'province_id', 'complexe_id'],
    ];

    public function __construct(
        private readonly Journal $journal,
        private readonly Perimetre $perimetres,
    ) {}

    /**
     * Niveaux que le rôle fait choisir à l'écran : la hiérarchie complète, ou le seul niveau
     * de la direction régionale. Vide pour les rôles nationaux.
     *
     * @return list<string>
     */
    public static function niveauxDuRole(?RoleCode $role): array
    {
        return match (true) {
            $role === null => [],
            isset(self::HIERARCHIE[$role->value]) => self::HIERARCHIE[$role->value],
            $role === RoleCode::DirRegionale => ['region_id'],
            default => [],
        };
    }

    /**
     * Ascendance complète d'une unité, telle qu'elle est enregistrée dans l'organisation.
     *
     * @return array<string, int|null> niveau => identifiant (nul en dessous de l'unité)
     */
    public static function ascendance(string $niveau, int $id): array
    {
        $chaine = array_fill_keys(self::NIVEAUX, null);
        $chemin = array_slice(self::NIVEAUX, 0, array_search($niveau, self::NIVEAUX, true) + 1);

        $requete = match ($niveau) {
            'internat_id' => DB::table('internat')->where('internat.id', $id)
                ->join('etablissement', 'etablissement.id', '=', 'internat.etablissement_id'),
            'etablissement_id' => DB::table('etablissement')->where('etablissement.id', $id),
            'complexe_id' => DB::table('complexe')->where('complexe.id', $id),
            'province_id' => DB::table('province')->where('province.id', $id),
            'region_id' => DB::table('region')->where('region.id', $id),
        };

        if (in_array('etablissement_id', $chemin, true)) {
            $requete->join('complexe', 'complexe.id', '=', 'etablissement.complexe_id');
        }
        if (in_array('complexe_id', $chemin, true)) {
            $requete->join('province', 'province.id', '=', 'complexe.province_id');
        }

        $ligne = $requete->first(array_map(fn (string $n) => match ($n) {
            'internat_id' => 'internat.id as internat_id',
            'etablissement_id' => 'etablissement.id as etablissement_id',
            'complexe_id' => 'complexe.id as complexe_id',
            'province_id' => 'province.id as province_id',
            'region_id' => $niveau === 'region_id' ? 'region.id as region_id' : 'province.region_id as region_id',
        }, $chemin));

        foreach ((array) $ligne as $colonne => $valeur) {
            $chaine[$colonne] = $valeur === null ? null : (int) $valeur;
        }

        return $chaine;
    }

    /**
     * Règles de saisie d'un compte, communes à la création et à la modification.
     *
     * @param  array<string, mixed>  $saisie
     * @return array<string, mixed>
     */
    public static function regles(?Utilisateur $compte, array $saisie): array
    {
        $role = RoleCode::tryFrom((string) ($saisie['role'] ?? ''));

        return [
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('utilisateur', 'email')->ignore($compte?->id)],
            'role' => ['required', Rule::enum(RoleCode::class)],
            'actif' => ['boolean'],
            'mot_de_passe' => [$compte ? 'nullable' : 'required', Password::defaults()],
            'internat_id' => [Rule::requiredIf($role === RoleCode::Gestionnaire), 'nullable', 'integer', 'exists:internat,id'],
            'etablissement_id' => [
                Rule::requiredIf(in_array($role, [RoleCode::ChefPole, RoleCode::Restauration], true)
                    || ($role === RoleCode::Directeur && blank($saisie['complexe_id'] ?? null))),
                'nullable', 'integer', 'exists:etablissement,id',
            ],
            'complexe_id' => ['nullable', 'integer', 'exists:complexe,id'],
            'province_id' => ['nullable', 'integer', 'exists:province,id'],
            'region_id' => [Rule::requiredIf($role === RoleCode::DirRegionale), 'nullable', 'integer', 'exists:region,id'],
        ];
    }

    /**
     * Colonnes de périmètre conservées pour le rôle choisi : un seul niveau.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, int|null>
     */
    public static function perimetre(RoleCode $role, array $donnees): array
    {
        $conserves = match ($role) {
            RoleCode::Gestionnaire => ['internat_id'],
            RoleCode::ChefPole, RoleCode::Restauration => ['etablissement_id'],
            RoleCode::Directeur => filled($donnees['complexe_id'] ?? null) ? ['complexe_id'] : ['etablissement_id'],
            RoleCode::DirRegionale => ['region_id'],
            default => [],
        };

        $perimetre = array_fill_keys(self::COLONNES_PERIMETRE, null);

        foreach ($conserves as $colonne) {
            $perimetre[$colonne] = (int) $donnees[$colonne];
        }

        return $perimetre;
    }

    /**
     * Le rattachement retenu doit correspondre aux niveaux parcourus pour l'atteindre (une
     * province de la région choisie, un internat de l'établissement choisi…) et relever du
     * périmètre de l'auteur : on ne confie pas une structure que l'on n'a pas soi-même en charge.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, int|null>  $perimetre
     */
    private function verifierRattachement(RoleCode $role, array $donnees, array $perimetre, Utilisateur $auteur): void
    {
        $niveau = collect(self::COLONNES_PERIMETRE)->first(fn (string $colonne) => $perimetre[$colonne] !== null);
        $accessibles = $this->perimetres->internatIds($auteur);

        if ($niveau === null) {
            if ($accessibles !== null) {
                throw new RegleMetierException('Un compte de portée nationale ne peut être créé que par un compte de portée nationale.', 'hors_perimetre');
            }

            return;
        }

        $chaine = self::ascendance($niveau, $perimetre[$niveau]);

        foreach (self::niveauxDuRole($role) as $parent) {
            if (filled($donnees[$parent] ?? null) && (int) $donnees[$parent] !== $chaine[$parent]) {
                throw new RegleMetierException(
                    'Le rattachement choisi ne correspond pas à la hiérarchie sélectionnée (région, province, complexe, établissement, internat).',
                    'hierarchie_incoherente',
                );
            }
        }

        if ($accessibles === null) {
            return;
        }

        $internats = match ($niveau) {
            'internat_id' => [$perimetre[$niveau]],
            'etablissement_id' => Internat::where('etablissement_id', $perimetre[$niveau])->pluck('id')->all(),
            'complexe_id' => Internat::whereHas('etablissement', fn ($q) => $q->where('complexe_id', $perimetre[$niveau]))->pluck('id')->all(),
            'region_id' => Internat::whereHas('etablissement.complexe.province', fn ($q) => $q->where('region_id', $perimetre[$niveau]))->pluck('id')->all(),
        };

        if ($internats === [] || array_diff($internats, $accessibles) !== []) {
            throw new RegleMetierException('Cette structure ne relève pas de votre périmètre.', 'hors_perimetre');
        }
    }

    /** @param array<string, mixed> $donnees données validées par `regles()` */
    public function creer(array $donnees, Utilisateur $auteur): Utilisateur
    {
        $role = RoleCode::from($donnees['role']);
        $perimetre = self::perimetre($role, $donnees);
        $this->verifierRattachement($role, $donnees, $perimetre, $auteur);

        return DB::transaction(function () use ($donnees, $role, $perimetre, $auteur) {
            $compte = Utilisateur::create([
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => mb_strtolower($donnees['email']),
                'mot_de_passe_hash' => $donnees['mot_de_passe'],
                'actif' => $donnees['actif'] ?? true,
            ] + $perimetre);

            $compte->syncRoles([$role->value]);

            $this->journal->consigner('utilisateur.creation', $compte, details: ['role' => $role->value, 'perimetre' => array_filter($perimetre)], acteur: $auteur);

            return $compte;
        });
    }

    /** @param array<string, mixed> $donnees données validées par `regles()` */
    public function modifier(Utilisateur $compte, array $donnees, Utilisateur $auteur): Utilisateur
    {
        $role = RoleCode::from($donnees['role']);
        $ancienRole = $compte->roleCode()?->value;
        $actif = (bool) ($donnees['actif'] ?? $compte->actif);

        if ($compte->is($auteur) && ($role->value !== $ancienRole || ! $actif)) {
            throw new RegleMetierException('Vous ne pouvez pas modifier votre propre rôle ni désactiver votre compte.', 'auto_modification');
        }

        $this->exigerUnAdministrateurRestant($compte, $role, $actif);
        $perimetre = self::perimetre($role, $donnees);
        $this->verifierRattachement($role, $donnees, $perimetre, $auteur);

        return DB::transaction(function () use ($compte, $donnees, $role, $ancienRole, $actif, $perimetre, $auteur) {
            $avant = $compte->only(['nom', 'prenom', 'email', 'actif', ...self::COLONNES_PERIMETRE]);

            $compte->fill([
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => mb_strtolower($donnees['email']),
                'actif' => $actif,
            ] + $perimetre);

            if (filled($donnees['mot_de_passe'] ?? null)) {
                $compte->mot_de_passe_hash = $donnees['mot_de_passe'];
            }

            $compte->save();
            $compte->syncRoles([$role->value]);

            $this->journal->consigner('utilisateur.modification', $compte, details: [
                'avant' => $avant + ['role' => $ancienRole],
                'apres' => $compte->only(array_keys($avant)) + ['role' => $role->value],
                'mot_de_passe_change' => filled($donnees['mot_de_passe'] ?? null),
            ], acteur: $auteur);

            return $compte;
        });
    }

    /** Active ou désactive un compte sans le supprimer. */
    public function basculer(Utilisateur $compte, Utilisateur $auteur): Utilisateur
    {
        if ($compte->is($auteur)) {
            throw new RegleMetierException('Vous ne pouvez pas désactiver votre propre compte.', 'auto_modification');
        }

        $role = $compte->roleCode();

        if ($role !== null) {
            $this->exigerUnAdministrateurRestant($compte, $role, ! $compte->actif);
        }

        $compte->forceFill(['actif' => ! $compte->actif])->save();
        $this->journal->consigner($compte->actif ? 'utilisateur.activation' : 'utilisateur.desactivation', $compte, acteur: $auteur);

        return $compte;
    }

    private function exigerUnAdministrateurRestant(Utilisateur $compte, RoleCode $nouveauRole, bool $actif): void
    {
        if ($compte->roleCode() !== RoleCode::Admin || ($nouveauRole === RoleCode::Admin && $actif)) {
            return;
        }

        $autres = Utilisateur::role(RoleCode::Admin->value)->where('actif', true)->whereKeyNot($compte->id)->count();

        if ($autres === 0) {
            throw new RegleMetierException('Au moins un administrateur actif doit subsister.', 'dernier_administrateur');
        }
    }
}
