<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\Referentiel;
use App\Enums\StatutBatiment;
use App\Enums\TypeChambre;
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
 */
#[Title('Plan d’occupation')]
class Plan extends PagePersonnel
{
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

    public function mount(): void
    {
        $this->exiger('referentiel.consulter');
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

    public function preparerChambre(int $batiment, ?int $id = null): void
    {
        $parent = Batiment::findOrFail($batiment);
        $this->gerer($parent);
        $chambre = $id !== null ? Chambre::where('batiment_id', $parent->id)->findOrFail($id) : null;

        $this->batimentId = $parent->id;
        $this->ligne = $chambre?->id;
        $this->chambre = [
            'numero' => $chambre->numero ?? '',
            'etage' => $chambre->etage ?? 0,
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
        $this->ouvrirDialogue('lit');
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
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger('referentiel.consulter');
        $utilisateur = $this->utilisateur();
        $internat = $this->internatChoisi($this->internat);
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
            'internats' => $this->internatsDuPerimetre(),
            'batiments' => $batiments,
            'totaux' => $totaux,
            'nominatif' => $nominatif,
            'gerer' => $internat !== null && $utilisateur->can('referentiel.gerer') && $this->perimetre()->couvreInternat($utilisateur, $internat->id),
            'litOuvert' => $litOuvert,
            'batimentDeLaChambre' => $this->dialogue === 'chambre' && $this->batimentId !== null ? $batiments->firstWhere('id', $this->batimentId) : null,
        ]);
    }
}
