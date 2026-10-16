<?php

namespace App\Livewire\Administration;

use App\Domain\Identity\ComptesPersonnel;
use App\Enums\RoleCode;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Complexe;
use App\Models\Etablissement;
use App\Models\Internat;
use App\Models\Region;
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
    use AvecTableau;

    private const PERMISSION = 'utilisateurs.gerer';

    /** Ce que chaque rôle fait, et le niveau de périmètre qu'il exige. */
    public const ROLES = [
        'ADMIN' => [null, 'Comptes, organisation, règles de gestion. Aucun accès aux dossiers.'],
        'GESTIONNAIRE' => ['internat', 'Ne voit que l’internat dont il a la charge.'],
        'CHEF_POLE' => ['etablissement', 'Campagnes, délibération et paiements des internats de son établissement.'],
        'RESTAURATION' => ['etablissement', 'Menus, effectifs et service des internats de son établissement.'],
        'DIRECTEUR' => ['complexe', 'Tableau de bord du complexe et première approbation des corrections.'],
        'DIR_REGIONALE' => ['region', 'Indicateurs de la région et seconde approbation des corrections.'],
        'DIR_CENTRALE' => [null, 'Pilotage national et grille de référence.'],
    ];

    private const VIDE = [
        'prenom' => '', 'nom' => '', 'email' => '', 'role' => 'GESTIONNAIRE', 'actif' => true, 'mot_de_passe' => '',
        'internat_id' => '', 'etablissement_id' => '', 'complexe_id' => '', 'region_id' => '',
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
        return ['role', 'actif'];
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

    public function render()
    {
        $this->exiger(self::PERMISSION);

        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';
        $roleFiltre = RoleCode::tryFrom($this->role);

        $requete = Utilisateur::query()->with('roles', 'internat:id,nom', 'etablissement:id,nom_efp', 'complexe:id,nom', 'region:id,nom')
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
            'filtresActifs' => $terme !== '' || $this->role !== '' || $this->actif !== '',
            // Listes de choix du périmètre, chargées seulement quand la fenêtre les montre.
            'internats' => $ouvert && $niveau === 'internat' ? Internat::orderBy('nom')->get()->mapWithKeys(fn (Internat $i) => [$i->id => $i->nom.' ('.$i->genre->libelle().')']) : collect(),
            'etablissements' => $ouvert && in_array($niveau, ['etablissement', 'complexe'], true) ? Etablissement::orderBy('nom_efp')->get()->mapWithKeys(fn (Etablissement $e) => [$e->id => $e->nom_efp.' ('.$e->code_efp.')']) : collect(),
            'complexes' => $ouvert && $niveau === 'complexe' ? Complexe::orderBy('nom')->pluck('nom', 'id') : collect(),
            'regions' => $ouvert && $niveau === 'region' ? Region::orderBy('nom')->pluck('nom', 'id') : collect(),
        ]);
    }
}
