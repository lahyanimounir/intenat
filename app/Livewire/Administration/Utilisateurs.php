<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\ComptesPersonnel;
use App\Enums\RoleCode;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Province;
use App\Models\Region;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
    use AvecTableau;

    private const PERMISSION = 'utilisateurs.gerer';

    /** Ce que chaque rôle fait, et le niveau de périmètre qu'il exige. */
    public const ROLES = [
        'ADMIN' => [null, 'Comptes, organisation, règles de gestion. Aucun accès aux dossiers.'],
        'GESTIONNAIRE' => ['internat', 'Ne voit que l’internat dont il a la charge.'],
        'CHEF_POLE' => ['etablissement', 'Campagnes, délibération et paiements des internats de son établissement.'],
        'RESTAURATION' => ['etablissement', 'Menus, effectifs et service des internats de son établissement.'],
        'DIRECTEUR' => ['complexe', 'Tableau de bord et indicateurs de son complexe / CMC.'],
        'DIR_REGIONALE' => ['region', 'Indicateurs consolidés de sa région.'],
        'DIR_CENTRALE' => [null, 'Pilotage national et grille de référence.'],
    ];

    private const VIDE = [
        'prenom' => '', 'nom' => '', 'email' => '', 'role' => 'GESTIONNAIRE', 'actif' => true, 'mot_de_passe' => '',
        'internat_id' => '', 'etablissement_id' => '', 'complexe_id' => '', 'region_id' => '',
    ];

    /*
     * Filtres hiérarchiques, du plus large au plus fin : chacun ne propose que les
     * enfants du niveau choisi au-dessus, et changer un niveau efface ceux d'en dessous.
     */
    #[Url(except: '')]
    public string $region = '';

    #[Url(except: '')]
    public string $province = '';

    #[Url(except: '')]
    public string $complexe = '';

    #[Url(except: '')]
    public string $etablissement = '';

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
        return ['region', 'province', 'complexe', 'etablissement', 'role', 'actif'];
    }

    public function updatedRegion(): void
    {
        $this->reset('province', 'complexe', 'etablissement');
    }

    public function updatedProvince(): void
    {
        $this->reset('complexe', 'etablissement');
    }

    public function updatedComplexe(): void
    {
        $this->reset('etablissement');
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

        $this->ligne = $compte->id;
        $this->saisie = [
            'prenom' => $compte->prenom, 'nom' => $compte->nom, 'email' => $compte->email,
            'role' => $compte->roleCode()?->value ?? 'GESTIONNAIRE', 'actif' => $compte->actif, 'mot_de_passe' => '',
            'internat_id' => (string) $compte->internat_id, 'etablissement_id' => (string) $compte->etablissement_id,
            'complexe_id' => (string) $compte->complexe_id, 'region_id' => (string) $compte->region_id,
        ];
        $this->ouvrirDialogue('compte');
    }

    public function enregistrer(ComptesPersonnel $comptes): void
    {
        $this->exiger(self::PERMISSION);
        $compte = $this->ligne !== null ? Utilisateur::with('roles')->findOrFail($this->ligne) : null;

        // Une liste laissée sur « Aucun » vaut absence de valeur.
        $saisie = array_map(fn ($valeur) => $valeur === '' ? null : $valeur, $this->saisie);
        $saisie['actif'] = (bool) $this->saisie['actif'];

        $regles = collect(ComptesPersonnel::regles($compte, $saisie))->mapWithKeys(fn ($regle, $champ) => ["saisie.{$champ}" => $regle])->all();

        $this->saisie = $saisie;
        $valides = $this->validate($regles, [], [
            'saisie.prenom' => 'prénom', 'saisie.nom' => 'nom', 'saisie.email' => 'adresse e-mail', 'saisie.role' => 'rôle',
            'saisie.mot_de_passe' => 'mot de passe', 'saisie.internat_id' => 'internat', 'saisie.etablissement_id' => 'établissement',
            'saisie.complexe_id' => 'complexe', 'saisie.region_id' => 'région',
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
     * Choix proposés à chaque niveau de la hiérarchie, limités aux établissements du périmètre du compte
     * connecté. Un choix qui n'appartient plus à son parent (adresse modifiée, unité supprimée) est effacé.
     *
     * @return array{regions: Collection<int, string>, provinces: Collection<int, string>, complexes: Collection<int, string>, etablissements: Collection<int, string>}
     */
    private function hierarchie(): array
    {
        $accessibles = $this->perimetre()->etablissementIds($this->utilisateur());
        $dansPerimetre = fn (string $chemin) => fn (Builder $q) => $accessibles === null
            ? $q
            : $q->whereHas($chemin, fn (Builder $e) => $e->whereIn('etablissement.id', $accessibles));

        $regions = Region::query()->tap($dansPerimetre('provinces.complexes.etablissements'))->orderBy('nom')->pluck('nom', 'id');
        if (! $regions->has((int) $this->region)) {
            $this->reset('region', 'province', 'complexe', 'etablissement');
        }

        $provinces = $this->region === '' ? collect() : Province::where('region_id', (int) $this->region)
            ->tap($dansPerimetre('complexes.etablissements'))->orderBy('nom')->pluck('nom', 'id');
        if (! $provinces->has((int) $this->province)) {
            $this->reset('province', 'complexe', 'etablissement');
        }

        $complexes = $this->province === '' ? collect() : Complexe::where('province_id', (int) $this->province)
            ->tap($dansPerimetre('etablissements'))->orderBy('nom')->pluck('nom', 'id');
        if (! $complexes->has((int) $this->complexe)) {
            $this->reset('complexe', 'etablissement');
        }

        $etablissements = $this->complexe === '' ? collect() : Etablissement::where('complexe_id', (int) $this->complexe)
            ->when($accessibles !== null, fn (Builder $q) => $q->whereIn('id', $accessibles))
            ->orderBy('nom_efp')->get()->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' ('.$e->code_efp.')']);
        if (! $etablissements->has((int) $this->etablissement)) {
            $this->reset('etablissement');
        }

        return compact('regions', 'provinces', 'complexes', 'etablissements');
    }

    /**
     * Comptes rattachés à l'unité choisie ou à une unité qui en dépend : une région retient sa direction
     * régionale, les directeurs de ses complexes, les chefs de pôle et responsables restauration de ses
     * établissements et les gestionnaires de ses internats. Les comptes nationaux n'y figurent pas.
     */
    private function restreindreAuRattachement(Builder $requete): void
    {
        [$etablissements, $complexes, $region] = match (true) {
            $this->etablissement !== '' => [fn () => Etablissement::whereKey((int) $this->etablissement)->select('id'), null, null],
            $this->complexe !== '' => [
                fn () => Etablissement::where('complexe_id', (int) $this->complexe)->select('id'),
                fn () => Complexe::whereKey((int) $this->complexe)->select('id'),
                null,
            ],
            $this->province !== '' => [
                fn () => Etablissement::whereIn('complexe_id', Complexe::where('province_id', (int) $this->province)->select('id'))->select('id'),
                fn () => Complexe::where('province_id', (int) $this->province)->select('id'),
                null,
            ],
            $this->region !== '' => [
                fn () => Etablissement::whereHas('complexe.province', fn (Builder $p) => $p->where('region_id', (int) $this->region))->select('id'),
                fn () => Complexe::whereHas('province', fn (Builder $p) => $p->where('region_id', (int) $this->region))->select('id'),
                (int) $this->region,
            ],
            default => [null, null, null],
        };

        if ($etablissements === null) {
            return;
        }

        $requete->where(function (Builder $q) use ($etablissements, $complexes, $region) {
            $q->whereIn('utilisateur.etablissement_id', $etablissements())
                ->orWhereIn('utilisateur.internat_id', Internat::whereIn('etablissement_id', $etablissements())->select('id'))
                ->when($complexes !== null, fn (Builder $s) => $s->orWhereIn('utilisateur.complexe_id', $complexes()))
                ->when($region !== null, fn (Builder $s) => $s->orWhere('utilisateur.region_id', $region));
        });
    }

    public function render()
    {
        $this->exiger(self::PERMISSION);

        $hierarchie = $this->hierarchie();
        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';
        $roleFiltre = RoleCode::tryFrom($this->role);

        $requete = Utilisateur::query()->with('roles', 'internat:id,nom', 'etablissement:id,nom_efp', 'complexe:id,nom', 'region:id,nom')
            ->tap(fn (Builder $q) => $this->restreindreAuRattachement($q))
            ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('email', 'like', $motif)))
            ->when($roleFiltre !== null, fn (Builder $q) => $q->role($roleFiltre->value))
            ->when(in_array($this->actif, ['0', '1'], true), fn (Builder $q) => $q->where('actif', $this->actif === '1'));

        $this->appliquerTri($requete, ['nom' => 'nom', 'email' => 'email', 'derniere_connexion' => 'derniere_connexion_le'], 'nom')->orderBy('id');

        $ouvert = $this->dialogue === 'compte';
        $niveau = self::ROLES[$this->saisie['role'] ?? ''][0] ?? null;

        return view('livewire.administration.utilisateurs', [
            'comptes' => $this->paginer($requete),
            'moi' => $this->utilisateur()->id,
            'roles' => collect(RoleCode::cases())->mapWithKeys(fn (RoleCode $r) => [$r->value => $r->libelle()]),
            'niveau' => $niveau,
            'aide' => self::ROLES[$this->saisie['role'] ?? ''][1] ?? null,
            'filtresActifs' => $terme !== '' || $this->role !== '' || $this->actif !== '' || $this->region !== '',
            'filtre' => $hierarchie,
            // Listes de choix du périmètre, chargées seulement quand la fenêtre les montre.
            'internats' => $ouvert && $niveau === 'internat' ? Internat::orderBy('nom')->get()->mapWithKeys(fn (Internat $i) => [$i->id => $i->nom.' ('.$i->genre->libelle().')']) : collect(),
            'etablissements' => $ouvert && in_array($niveau, ['etablissement', 'complexe'], true) ? Etablissement::orderBy('nom_efp')->get()->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' ('.$e->code_efp.')']) : collect(),
            'complexes' => $ouvert && $niveau === 'complexe' ? Complexe::orderBy('nom')->pluck('nom', 'id') : collect(),
            'regions' => $ouvert && $niveau === 'region' ? Region::orderBy('nom')->pluck('nom', 'id') : collect(),
        ]);
    }
}
