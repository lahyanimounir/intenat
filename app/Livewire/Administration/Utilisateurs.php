<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\ComptesPersonnel;
use App\Enums\RoleCode;
use App\Livewire\Concerns\AvecHierarchie;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Internat;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Comptes du personnel. Chaque compte porte un rôle et un périmètre : il
 * n'accède qu'aux données de l'internat, de l'établissement ou de la région
 * dont il a la charge. Les stagiaires créent eux-mêmes leur compte, ailleurs.
 */
#[Title('Utilisateurs')]
class Utilisateurs extends PagePersonnel
{
    use AvecHierarchie, AvecTableau;

    private const PERMISSION = 'utilisateurs.gerer';

    /** Ce que chaque rôle fait, et le niveau de périmètre qu'il exige. */
    public const ROLES = [
        'ADMIN' => [null, 'Comptes, organisation, règles de gestion. Aucun accès aux dossiers.'],
        'GESTIONNAIRE' => ['internat', 'Ne voit que l’internat dont il a la charge.'],
        'CHEF_POLE' => ['etablissement', 'Campagnes, délibération et paiements des internats de son établissement.'],
        'RESTAURATION' => ['etablissement', 'Menus, effectifs et service des internats de son établissement.'],
        'DIRECTEUR' => ['complexe', 'Délibération, tableau de bord et indicateurs de son complexe / CMC.'],
        'DIR_REGIONALE' => ['region', 'Indicateurs consolidés de sa région.'],
        'DIR_CENTRALE' => [null, 'Pilotage national et grille de référence.'],
    ];

    private const VIDE = [
        'prenom' => '', 'nom' => '', 'email' => '', 'role' => 'GESTIONNAIRE', 'actif' => true, 'mot_de_passe' => '',
        'region_id' => '', 'province_id' => '', 'complexe_id' => '', 'etablissement_id' => '', 'internat_id' => '',
    ];

    #[Url(except: '')]
    public string $role = '';

    /** « 1 » : comptes actifs ; « 0 » : comptes désactivés. */
    #[Url(as: 'etat', except: '')]
    public string $actif = '';

    /** Compte en cours de modification ; nul pour une création. */
    #[Locked]
    public ?int $ligne = null;

    /** @var array<string, mixed> */
    public array $saisie = self::VIDE;

    public function mount(): void
    {
        $this->exiger(self::PERMISSION);
    }

    protected function filtres(): array
    {
        return [...$this->filtresHierarchiques(), 'role', 'actif'];
    }

    public function effacerFiltres(): void
    {
        $this->reset('recherche', 'region', 'province', 'complexe', 'etablissement', 'role', 'actif');
        $this->resetPage();
    }

    public function creer(): void
    {
        $this->exiger(self::PERMISSION);
        $this->ligne = null;
        $this->saisie = self::VIDE;
        $this->ouvrirDialogue('compte');
    }

    public function modifier(int $id): void
    {
        $this->exiger(self::PERMISSION);
        $compte = Utilisateur::with('roles')->findOrFail($id);

        $role = $compte->roleCode() ?? RoleCode::Gestionnaire;

        // La hiérarchie se reconstitue depuis le rattachement actuel du compte, quel qu'en soit le niveau.
        $rattachement = collect(['internat_id', 'etablissement_id', 'complexe_id', 'region_id'])->first(fn (string $colonne) => $compte->{$colonne} !== null);
        $chaine = $rattachement !== null ? ComptesPersonnel::ascendance($rattachement, $compte->{$rattachement}) : [];
        $niveaux = ComptesPersonnel::niveauxDuRole($role);

        $this->ligne = $compte->id;
        $this->saisie = [
            'prenom' => $compte->prenom, 'nom' => $compte->nom, 'email' => $compte->email,
            'role' => $role->value, 'actif' => $compte->actif, 'mot_de_passe' => '',
        ] + collect(ComptesPersonnel::NIVEAUX)->mapWithKeys(fn (string $niveau) => [
            $niveau => in_array($niveau, $niveaux, true) ? (string) ($chaine[$niveau] ?? '') : '',
        ])->all();
        $this->ouvrirDialogue('compte');
    }

    /**
     * Le formulaire suit la hiérarchie : changer de rôle efface les niveaux qu'il ne demande plus,
     * changer un niveau efface ceux d'en dessous.
     */
    public function updatedSaisie(mixed $valeur, string $cle): void
    {
        $niveaux = ComptesPersonnel::NIVEAUX;

        if ($cle === 'role') {
            $utiles = ComptesPersonnel::niveauxDuRole(RoleCode::tryFrom((string) $valeur));
            foreach (array_diff($niveaux, $utiles) as $niveau) {
                $this->saisie[$niveau] = '';
            }

            return;
        }

        $rang = array_search($cle, $niveaux, true);
        if ($rang !== false) {
            foreach (array_slice($niveaux, $rang + 1) as $niveau) {
                $this->saisie[$niveau] = '';
            }
        }
    }

    public function enregistrer(ComptesPersonnel $comptes): void
    {
        $this->exiger(self::PERMISSION);
        $compte = $this->ligne !== null ? Utilisateur::with('roles')->findOrFail($this->ligne) : null;

        // Une liste laissée sur « Aucun » vaut absence de valeur.
        $saisie = array_map(fn ($valeur) => $valeur === '' ? null : $valeur, $this->saisie);
        $saisie['actif'] = (bool) $this->saisie['actif'];

        $regles = collect(ComptesPersonnel::regles($compte, $saisie))->mapWithKeys(fn ($regle, $champ) => ["saisie.{$champ}" => $regle])->all();

        // Chaque niveau de la hiérarchie du rôle est à choisir, jusqu'au rattachement.
        foreach (ComptesPersonnel::niveauxDuRole(RoleCode::tryFrom((string) $saisie['role'])) as $niveau) {
            $regles["saisie.{$niveau}"] = ['required', ...$regles["saisie.{$niveau}"]];
        }

        $this->saisie = $saisie;
        $valides = $this->validate($regles, [], [
            'saisie.prenom' => 'prénom', 'saisie.nom' => 'nom', 'saisie.email' => 'adresse e-mail', 'saisie.role' => 'rôle',
            'saisie.mot_de_passe' => 'mot de passe', 'saisie.internat_id' => 'internat', 'saisie.etablissement_id' => 'établissement',
            'saisie.complexe_id' => 'complexe / CMC', 'saisie.province_id' => 'province', 'saisie.region_id' => 'région',
        ])['saisie'];

        $reussi = $this->executer(
            fn () => $compte ? $comptes->modifier($compte, $valides, $this->utilisateur()) : $comptes->creer($valides, $this->utilisateur()),
            $compte ? 'Compte mis à jour.' : 'Compte créé.',
        );

        if ($reussi) {
            $this->fermerDialogue();
        }
    }

    public function basculer(ComptesPersonnel $comptes, int $id): void
    {
        $this->exiger(self::PERMISSION);
        $compte = Utilisateur::with('roles')->findOrFail($id);

        $this->executer(function () use ($comptes, $compte) {
            $comptes->basculer($compte, $this->utilisateur());
            $this->message($compte->actif ? 'Compte réactivé.' : 'Compte désactivé : il ne peut plus se connecter.');
        });
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->saisie = self::VIDE;
        $this->dialogue = null;
        $this->resetValidation();
    }

    /**
     * Comptes rattachés à l'unité choisie ou à une unité qui en dépend : une région retient sa direction
     * régionale, les directeurs de ses complexes, les chefs de pôle et responsables restauration de ses
     * établissements et les gestionnaires de ses internats. Les comptes nationaux n'y figurent pas.
     */
    private function restreindreAuRattachement(Builder $requete): void
    {
        $etablissements = fn () => $this->etablissementsFiltres();

        if ($etablissements() === null) {
            return;
        }

        $complexes = $this->complexesFiltres();
        // La direction régionale n'est retenue que si la région est le niveau le plus fin choisi.
        $region = $this->province === '' ? (int) $this->region : null;

        $requete->where(function (Builder $q) use ($etablissements, $complexes, $region) {
            $q->whereIn('utilisateur.etablissement_id', $etablissements())
                ->orWhereIn('utilisateur.internat_id', Internat::whereIn('etablissement_id', $etablissements())->select('id'))
                ->when($complexes !== null, fn (Builder $s) => $s->orWhereIn('utilisateur.complexe_id', $complexes))
                ->when($region !== null, fn (Builder $s) => $s->orWhere('utilisateur.region_id', $region));
        });
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);

        $hierarchie = $this->listesDesFiltres();
        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';
        $roleFiltre = RoleCode::tryFrom($this->role);

        $requete = Utilisateur::query()->with('roles', 'internat:id,nom', 'etablissement:id,nom_efp', 'complexe:id,nom', 'region:id,nom')
            ->tap(fn (Builder $q) => $this->restreindreAuRattachement($q))
            ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('email', 'like', $motif)))
            ->when($roleFiltre !== null, fn (Builder $q) => $q->role($roleFiltre->value))
            ->when(in_array($this->actif, ['0', '1'], true), fn (Builder $q) => $q->where('actif', $this->actif === '1'));

        $this->appliquerTri($requete, ['nom' => 'nom', 'email' => 'email', 'derniere_connexion' => 'derniere_connexion_le'], 'nom')->orderBy('id');

        // Listes du formulaire, chargées seulement quand la fenêtre les montre.
        $niveaux = $this->dialogue === 'compte' ? ComptesPersonnel::niveauxDuRole(RoleCode::tryFrom((string) ($this->saisie['role'] ?? ''))) : [];
        [$listes, $choix] = $niveaux === [] ? [[], []] : $this->cascade($this->saisie, $niveaux);
        $this->saisie = $choix + $this->saisie;

        return view('livewire.administration.utilisateurs', [
            'comptes' => $this->paginer($requete),
            'moi' => $this->utilisateur()->id,
            'roles' => collect(RoleCode::cases())->mapWithKeys(fn (RoleCode $r) => [$r->value => $r->libelle()]),
            'niveaux' => $niveaux,
            'listes' => $listes,
            'aide' => self::ROLES[$this->saisie['role'] ?? ''][1] ?? null,
            'filtresActifs' => $terme !== '' || $this->role !== '' || $this->actif !== '' || $this->hierarchieFiltree(),
            'filtre' => $hierarchie,
        ]);
    }
}
