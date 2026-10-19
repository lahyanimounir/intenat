<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\Referentiel;
use App\Domain\Identity\ComptesPersonnel;
use App\Enums\StatutBatiment;
use App\Enums\TypeChambre;
use App\Livewire\Concerns\AvecHierarchie;
use App\Livewire\PagePersonnel;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\Lit;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Plan d'occupation d'un internat : bâtiments, étages, chambres et lits, avec
 * l'état de chaque lit. Le gestionnaire y tient son référentiel à la main, en
 * complément de l'import CSV. L'identité des occupants n'est montrée qu'aux
 * rôles chargés de l'hébergement.
 *
 * Un compte qui a plusieurs internats en charge atteint le sien par la hiérarchie
 * Région → Province → Complexe / CMC → Établissement → Internat ; à l'ouverture, le
 * premier internat de son périmètre est choisi, comme auparavant. Un compte attaché
 * à un seul internat n'a rien à choisir.
 */
#[Title('Plan d’occupation')]
class Plan extends PagePersonnel
{
    use AvecHierarchie;

    /** Internat affiché : dernier niveau de la hiérarchie, il fixe les niveaux au-dessus. */
    #[Url(except: '')]
    public string $internat = '';

    /** Ligne visée par la fenêtre ouverte : bâtiment, chambre ou lit selon la fenêtre. */
    #[Locked]
    public ?int $ligne = null;

    /** Bâtiment dans lequel une chambre est créée. */
    #[Locked]
    public ?int $batimentId = null;

    /** @var array<string, mixed> */
    public array $batiment = [];

    /** @var array<string, mixed> */
    public array $chambre = [];

    public string $numeroLit = '';

    /** Étage visé par la fenêtre de renumérotation. */
    #[Locked]
    public ?int $etageVise = null;

    public string $nouvelEtage = '';

    public function mount(): void
    {
        $this->exiger('referentiel.consulter');

        // Sans choix dans l'adresse, le premier internat du périmètre reste proposé par défaut.
        if ($this->internat === '' && $this->region === '' && $this->plusieursInternats()) {
            $this->internat = (string) ($this->internatChoisi(null)?->id ?? '');
        }
    }

    private function plusieursInternats(): bool
    {
        return $this->internatsDuPerimetre()->count() > 1;
    }

    /* ------------------------------------------------- Hiérarchie : changer un niveau efface ceux d'en dessous */

    public function updatedRegion(): void
    {
        $this->reset('province', 'complexe', 'etablissement', 'internat');
    }

    public function updatedProvince(): void
    {
        $this->reset('complexe', 'etablissement', 'internat');
    }

    public function updatedComplexe(): void
    {
        $this->reset('etablissement', 'internat');
    }

    public function updatedEtablissement(): void
    {
        $this->reset('internat');
    }

    private function gerer(Batiment|Chambre|Lit $modele): void
    {
        $this->autoriser($modele, 'referentiel.gerer');
    }

    /* ------------------------------------------------------------ Bâtiments */

    public function preparerBatiment(?int $id = null): void
    {
        $this->exiger('referentiel.gerer');
        $batiment = $id !== null ? Batiment::findOrFail($id) : null;

        if ($batiment !== null) {
            $this->gerer($batiment);
        }

        $this->ligne = $batiment?->id;
        $this->batiment = [
            'code' => $batiment->code ?? '',
            'nom' => $batiment->nom ?? '',
            'nombre_etages' => $batiment->nombre_etages ?? 0,
            'statut' => $batiment?->statut->value ?? StatutBatiment::Ouvert->value,
            'equipements_communs' => $batiment->equipements_communs ?? '',
        ];
        $this->ouvrirDialogue('batiment');
    }

    public function enregistrerBatiment(Referentiel $referentiel): void
    {
        $this->exiger('referentiel.gerer');
        $batiment = $this->ligne !== null ? Batiment::findOrFail($this->ligne) : null;
        $internat = $batiment !== null ? $batiment->internat : $this->internatChoisi($this->internat);
        abort_if($internat === null, 404);
        $this->perimetre()->exigerInternat($this->utilisateur(), $internat->id);

        $valides = $this->validate([
            'batiment.code' => ['required', 'string', 'max:20'],
            'batiment.nom' => ['required', 'string', 'max:100'],
            'batiment.nombre_etages' => ['required', 'integer', 'min:0', 'max:30'],
            'batiment.statut' => ['required', Rule::enum(StatutBatiment::class)],
            'batiment.equipements_communs' => ['nullable', 'string', 'max:1000'],
        ], attributes: [
            'batiment.code' => 'code', 'batiment.nom' => 'nom', 'batiment.nombre_etages' => 'nombre d\'étages',
            'batiment.statut' => 'statut', 'batiment.equipements_communs' => 'équipements communs',
        ])['batiment'];

        $valides['nombre_etages'] = (int) $valides['nombre_etages'];
        $valides['equipements_communs'] = ($valides['equipements_communs'] ?? '') === '' ? null : $valides['equipements_communs'];

        if ($this->executer(fn () => $referentiel->enregistrerBatiment($internat, $batiment, $valides), $batiment ? 'Bâtiment modifié.' : 'Bâtiment ajouté.')) {
            $this->fermerDialogue();
        }
    }

    /* ------------------------------------------------------------- Chambres */

    public function preparerChambre(int $batiment, ?int $id = null, ?int $etage = null): void
    {
        $parent = Batiment::findOrFail($batiment);
        $this->gerer($parent);
        $chambre = $id !== null ? Chambre::where('batiment_id', $parent->id)->findOrFail($id) : null;

        $this->batimentId = $parent->id;
        $this->ligne = $chambre?->id;
        $this->chambre = [
            'numero' => $chambre->numero ?? '',
            'etage' => $chambre->etage ?? $etage ?? 0,
            'type_chambre' => $chambre?->type_chambre->value ?? TypeChambre::Standard->value,
            'capacite' => $chambre->capacite ?? 2,
            'equipements' => $chambre->equipements ?? '',
        ];
        $this->ouvrirDialogue('chambre');
    }

    public function enregistrerChambre(Referentiel $referentiel): void
    {
        $batiment = Batiment::findOrFail($this->batimentId);
        $this->gerer($batiment);
        $chambre = $this->ligne !== null ? Chambre::where('batiment_id', $batiment->id)->findOrFail($this->ligne) : null;

        $valides = $this->validate([
            'chambre.numero' => ['required', 'string', 'max:30'],
            'chambre.etage' => ['required', 'integer', 'min:0', 'max:'.$batiment->nombre_etages],
            'chambre.type_chambre' => ['required', Rule::enum(TypeChambre::class)],
            'chambre.capacite' => ['required', 'integer', 'min:1', 'max:20'],
            'chambre.equipements' => ['nullable', 'string', 'max:1000'],
        ], ['chambre.etage.max' => 'L\'étage dépasse le nombre d\'étages du bâtiment.'], [
            'chambre.numero' => 'numéro', 'chambre.etage' => 'étage', 'chambre.type_chambre' => 'type de chambre',
            'chambre.capacite' => 'capacité', 'chambre.equipements' => 'équipements',
        ])['chambre'];

        $valides['etage'] = (int) $valides['etage'];
        $valides['capacite'] = (int) $valides['capacite'];
        $valides['equipements'] = ($valides['equipements'] ?? '') === '' ? null : $valides['equipements'];

        if ($this->executer(fn () => $referentiel->enregistrerChambre($batiment, $chambre, $valides), $chambre ? 'Chambre modifiée.' : 'Chambre ajoutée.')) {
            $this->fermerDialogue();
        }
    }

    /* ----------------------------------------------------------------- Lits */

    public function preparerLit(int $chambre): void
    {
        $parent = Chambre::findOrFail($chambre);
        $this->gerer($parent);

        $this->ligne = $parent->id;
        $this->numeroLit = (string) ($parent->lits()->count() + 1);
        $this->ouvrirDialogue('nouveau-lit');
    }

    public function ajouterLit(Referentiel $referentiel): void
    {
        $chambre = Chambre::findOrFail($this->ligne);
        $this->gerer($chambre);

        $numero = $this->validate(['numeroLit' => ['required', 'string', 'max:10']], attributes: ['numeroLit' => 'numéro du lit'])['numeroLit'];

        if ($this->executer(fn () => $referentiel->ajouterLit($chambre, $numero), 'Lit ajouté.')) {
            $this->fermerDialogue();
        }
    }

    public function voirLit(int $lit): void
    {
        $this->autoriser(Lit::findOrFail($lit), 'referentiel.consulter');

        $this->ligne = $lit;
        $this->numeroLit = (string) Lit::findOrFail($lit)->numero;
        $this->ouvrirDialogue('lit');
    }

    public function renommerLit(Referentiel $referentiel): void
    {
        $lit = Lit::findOrFail($this->ligne);
        $this->gerer($lit);

        $numero = $this->validate(['numeroLit' => ['required', 'string', 'max:10']], attributes: ['numeroLit' => 'numéro du lit'])['numeroLit'];

        $this->executer(fn () => $referentiel->renommerLit($lit, $numero), 'Lit renommé.');
    }

    public function supprimerLit(Referentiel $referentiel): void
    {
        $lit = Lit::findOrFail($this->ligne);
        $this->gerer($lit);

        if ($this->executer(fn () => $referentiel->supprimerLit($lit), 'Lit supprimé.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimerChambre(Referentiel $referentiel, int $id): void
    {
        $chambre = Chambre::findOrFail($id);
        $this->gerer($chambre);

        $this->executer(fn () => $referentiel->supprimerChambre($chambre), 'Chambre supprimée avec ses lits.');
    }

    public function supprimerBatiment(Referentiel $referentiel, int $id): void
    {
        $batiment = Batiment::findOrFail($id);
        $this->gerer($batiment);

        $this->executer(fn () => $referentiel->supprimerBatiment($batiment), 'Bâtiment supprimé avec ses étages, chambres et lits.');
    }

    /* --------------------------------------------------------------- Étages */

    public function ajouterEtage(Referentiel $referentiel, int $batiment): void
    {
        $modele = Batiment::findOrFail($batiment);
        $this->gerer($modele);

        $this->executer(fn () => $referentiel->ajouterEtage($modele), 'Étage ajouté au sommet du bâtiment.');
    }

    public function preparerEtage(int $batiment, int $etage): void
    {
        $modele = Batiment::findOrFail($batiment);
        $this->gerer($modele);

        $this->batimentId = $modele->id;
        $this->etageVise = $etage;
        $this->nouvelEtage = (string) $etage;
        $this->ouvrirDialogue('etage');
    }

    public function renumeroterEtage(Referentiel $referentiel): void
    {
        $batiment = Batiment::findOrFail($this->batimentId);
        $this->gerer($batiment);

        $nouveau = (int) $this->validate(
            ['nouvelEtage' => ['required', 'integer', 'min:0', 'max:'.$batiment->nombre_etages]],
            ['nouvelEtage.max' => 'Le bâtiment compte les étages 0 à '.$batiment->nombre_etages.'.'],
            ['nouvelEtage' => 'nouvel étage'],
        )['nouvelEtage'];

        if ($this->executer(fn () => $referentiel->renumeroterEtage($batiment, (int) $this->etageVise, $nouveau), 'Étage renuméroté : ses chambres ont été déplacées.')) {
            $this->fermerDialogue();
        }
    }

    public function supprimerEtage(Referentiel $referentiel, int $batiment, int $etage): void
    {
        $modele = Batiment::findOrFail($batiment);
        $this->gerer($modele);

        $this->executer(fn () => $referentiel->supprimerEtage($modele, $etage), 'Étage supprimé avec ses chambres et ses lits.');
    }

    public function basculerLit(Referentiel $referentiel, bool $horsService): void
    {
        $lit = Lit::findOrFail($this->ligne);
        $this->gerer($lit);

        $this->executer(fn () => $referentiel->basculerLit($lit, $horsService), $horsService ? 'Lit mis hors service.' : 'Lit remis en service.');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->batimentId = null;
        $this->etageVise = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger('referentiel.consulter');
        $utilisateur = $this->utilisateur();
        $plusieurs = $this->plusieursInternats();
        $filtre = null;

        if ($plusieurs) {
            // Un internat hors du périmètre est refusé ; un internat choisi fixe les niveaux au-dessus de lui.
            $internat = $this->internat === '' ? null : $this->internatChoisi($this->internat);

            if ($internat !== null) {
                $chaine = ComptesPersonnel::ascendance('internat_id', $internat->id);
                [$this->region, $this->province, $this->complexe, $this->etablissement] = array_map(
                    fn ($id) => (string) ($id ?? ''),
                    [$chaine['region_id'], $chaine['province_id'], $chaine['complexe_id'], $chaine['etablissement_id']],
                );
            }

            [$listes, $choix] = $this->cascade([
                'region_id' => $this->region, 'province_id' => $this->province, 'complexe_id' => $this->complexe,
                'etablissement_id' => $this->etablissement, 'internat_id' => $this->internat,
            ], ComptesPersonnel::NIVEAUX);
            [$this->region, $this->province, $this->complexe, $this->etablissement, $this->internat] = array_values($choix);

            $filtre = [
                'regions' => $listes['region_id'], 'provinces' => $listes['province_id'], 'complexes' => $listes['complexe_id'],
                'etablissements' => $listes['etablissement_id'], 'internats' => $listes['internat_id'],
            ];
        } else {
            $internat = $this->internatChoisi($this->internat);
        }

        $nominatif = $utilisateur->can('hebergement.consulter');

        $batiments = $internat === null ? collect() : Batiment::where('internat_id', $internat->id)->orderBy('code')
            ->with([
                'chambres' => fn ($q) => $q->orderBy('etage')->orderBy('numero'),
                'chambres.lits' => fn ($q) => $q->orderBy('numero'),
                'chambres.lits.affectationActive.demande.stagiaire:id,nom,prenom,cef',
                'chambres.lits.affectationActive.mouvements',
            ])->get();

        $totaux = ['lits' => 0, 'disponible' => 0, 'occupe' => 0, 'reserve' => 0, 'hors_service' => 0, 'en_maintenance' => 0];

        foreach ($batiments as $batiment) {
            foreach ($batiment->chambres as $chambre) {
                foreach ($chambre->lits as $lit) {
                    $lit->etat = Referentiel::etatLit($lit, $chambre);
                    $totaux['lits']++;
                    $totaux[$lit->etat]++;
                }
            }
        }

        $litOuvert = null;

        if ($this->dialogue === 'lit' && $this->ligne !== null) {
            $litOuvert = Lit::with('chambre.batiment', 'affectationActive.demande.stagiaire:id,nom,prenom,cef', 'affectationActive.mouvements')->find($this->ligne);

            if ($litOuvert !== null) {
                $litOuvert->etat = Referentiel::etatLit($litOuvert, $litOuvert->chambre);
                // L'historique nominatif des occupants n'est lu que par les rôles chargés de l'hébergement.
                $litOuvert->historique = $nominatif
                    ? $litOuvert->affectations()->with('demande.stagiaire:id,nom,prenom,cef', 'affecteur:id,nom,prenom')->orderByDesc('date_debut')->orderByDesc('id')->get()
                    : collect();
            }
        }

        return view('livewire.personnel.plan', [
            'choisi' => $internat,
            'filtre' => $filtre,
            'batiments' => $batiments,
            'totaux' => $totaux,
            'nominatif' => $nominatif,
            'gerer' => $internat !== null && $utilisateur->can('referentiel.gerer') && $this->perimetre()->couvreInternat($utilisateur, $internat->id),
            'litOuvert' => $litOuvert,
            'batimentDeLaChambre' => $this->dialogue === 'chambre' && $this->batimentId !== null ? $batiments->firstWhere('id', $this->batimentId) : null,
        ]);
    }
}
