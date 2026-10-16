<?php

namespace App\Http\Controllers\Api\Administration;

use App\Domain\Audit\Journal;
use App\Domain\Identity\Permissions;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnregistrerUtilisateurRequest;
use App\Http\Resources\UtilisateurResource;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UtilisateurController extends Controller
{
    private const RELATIONS = ['roles', 'internat', 'etablissement', 'complexe', 'region'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $requete = Utilisateur::query()->with(self::RELATIONS)
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $terme = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('recherche')->trim()).'%';
                $q->where(fn ($s) => $s->where('nom', 'like', $terme)->orWhere('prenom', 'like', $terme)->orWhere('email', 'like', $terme));
            })
            ->when($request->filled('role'), fn ($q) => $q->role($request->string('role')->toString()))
            ->when($request->has('actif') && $request->query('actif') !== '', fn ($q) => $q->where('actif', $request->boolean('actif')));

        $this->trier($requete, $request, ['nom' => 'nom', 'email' => 'email', 'derniere_connexion' => 'derniere_connexion_le'], 'nom');

        return UtilisateurResource::collection($this->paginer($requete, $request));
    }

    public function store(EnregistrerUtilisateurRequest $request, Journal $journal): JsonResponse
    {
        $utilisateur = DB::transaction(function () use ($request, $journal) {
            $donnees = $request->validated();

            $utilisateur = Utilisateur::create([
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => mb_strtolower($donnees['email']),
                'mot_de_passe_hash' => $donnees['mot_de_passe'],
                'actif' => $donnees['actif'] ?? true,
            ] + $request->perimetre());

            $utilisateur->syncRoles([$donnees['role']]);

            $journal->consigner('utilisateur.creation', $utilisateur, details: [
                'role' => $donnees['role'], 'perimetre' => array_filter($request->perimetre()),
            ]);

            return $utilisateur;
        });

        return (new UtilisateurResource($utilisateur->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Utilisateur $utilisateur): UtilisateurResource
    {
        return new UtilisateurResource($utilisateur->load(self::RELATIONS));
    }

    public function update(EnregistrerUtilisateurRequest $request, Utilisateur $utilisateur, Journal $journal): UtilisateurResource
    {
        $donnees = $request->validated();
        $ancienRole = $utilisateur->roleCode()?->value;

        if ($utilisateur->is($request->user()) && ($donnees['role'] !== $ancienRole || ($donnees['actif'] ?? true) === false)) {
            throw new RegleMetierException('Vous ne pouvez pas modifier votre propre rôle ni désactiver votre compte.', 'auto_modification');
        }

        $this->exigerUnAdministrateurRestant($utilisateur, $donnees['role'], $donnees['actif'] ?? $utilisateur->actif);

        DB::transaction(function () use ($utilisateur, $donnees, $request, $journal, $ancienRole) {
            $avant = $utilisateur->only(['nom', 'prenom', 'email', 'actif', 'internat_id', 'etablissement_id', 'complexe_id', 'region_id']);

            $utilisateur->fill([
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => mb_strtolower($donnees['email']),
                'actif' => $donnees['actif'] ?? $utilisateur->actif,
            ] + $request->perimetre());

            if (! empty($donnees['mot_de_passe'])) {
                $utilisateur->mot_de_passe_hash = $donnees['mot_de_passe'];
            }

            $utilisateur->save();
            $utilisateur->syncRoles([$donnees['role']]);

            $journal->consigner('utilisateur.modification', $utilisateur, details: [
                'avant' => $avant + ['role' => $ancienRole],
                'apres' => $utilisateur->only(array_keys($avant)) + ['role' => $donnees['role']],
                'mot_de_passe_change' => ! empty($donnees['mot_de_passe']),
            ]);
        });

        return new UtilisateurResource($utilisateur->fresh()->load(self::RELATIONS));
    }

    /** Active ou désactive un compte sans le supprimer (l'historique reste rattaché). */
    public function basculer(Request $request, Utilisateur $utilisateur, Journal $journal): UtilisateurResource
    {
        if ($utilisateur->is($request->user())) {
            throw new RegleMetierException('Vous ne pouvez pas désactiver votre propre compte.', 'auto_modification');
        }

        $this->exigerUnAdministrateurRestant($utilisateur, (string) $utilisateur->roleCode()?->value, ! $utilisateur->actif);

        $utilisateur->forceFill(['actif' => ! $utilisateur->actif])->save();
        $journal->consigner($utilisateur->actif ? 'utilisateur.activation' : 'utilisateur.desactivation', $utilisateur);

        return new UtilisateurResource($utilisateur->load(self::RELATIONS));
    }

    /** Rôles, permissions et matrice : référence de l'écran d'administration. */
    public function matrice(): JsonResponse
    {
        $niveaux = Permissions::niveauxDePerimetre();

        return response()->json([
            'permissions' => collect(Permissions::catalogue())
                ->map(fn (string $libelle, string $code) => ['code' => $code, 'libelle' => $libelle])->values(),
            'roles' => collect(RoleCode::cases())->map(fn (RoleCode $role) => [
                'code' => $role->value,
                'libelle' => $role->libelle(),
                'perimetre' => $niveaux[$role->value],
                'permissions' => Permissions::matrice()[$role->value],
            ]),
        ]);
    }

    private function exigerUnAdministrateurRestant(Utilisateur $utilisateur, string $nouveauRole, bool $actif): void
    {
        if ($utilisateur->roleCode() !== RoleCode::Admin || ($nouveauRole === RoleCode::Admin->value && $actif)) {
            return;
        }

        $autres = Utilisateur::role(RoleCode::Admin->value)->where('actif', true)->whereKeyNot($utilisateur->id)->count();

        if ($autres === 0) {
            throw new RegleMetierException('Au moins un administrateur actif doit subsister.', 'dernier_administrateur');
        }
    }
}
