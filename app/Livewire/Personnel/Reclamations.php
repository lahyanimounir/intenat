<?php

namespace App\Livewire\Personnel;

use App\Domain\Residence\VieInternat;
use App\Enums\DomaineReclamation;
use App\Enums\RoleCode;
use App\Enums\StatutReclamation;
use App\Livewire\PagePersonnel;
use App\Models\JournalAudit;
use App\Models\Reclamation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Réclamations des stagiaires. Le domaine choisi au dépôt désigne le
 * responsable : hébergement → gestionnaire, restauration → responsable de la
 * restauration, sélection → chef de pôle. Chacun ne traite que les
 * réclamations de son domaine, dans son périmètre.
 *
 * Le chef de pôle suit en outre toutes les réclamations de son périmètre, quel
 * qu'en soit le domaine et même clôturées, en consultation seule : message du
 * stagiaire, réponse, état et historique.
 */
#[Title('Réclamations')]
class Reclamations extends PagePersonnel
{
    use WithPagination;

    private const PERMISSION = 'reclamations.traiter';

    #[Url(except: '')]
    public string $statut = '';

    /** Suivi du chef de pôle : domaine affiché (vide : tous). */
    #[Url(except: '')]
    public string $domaine = '';

    /** Recherche d'un stagiaire : nom, prénom ou CEF. */
    #[Url(except: '')]
    public string $stagiaire = '';

    #[Locked]
    public ?int $ligne = null;

    public string $reponse = '';

    public function mount(): void
    {
        $this->domaineTraite();
    }

    public function updated(string $propriete): void
    {
        if (in_array($propriete, ['statut', 'domaine', 'stagiaire'], true)) {
            $this->resetPage();
        }
    }

    public function effacerFiltres(): void
    {
        $this->reset('statut', 'domaine', 'stagiaire');
        $this->resetPage();
    }

    /** Domaine dont répond le rôle du compte ; un rôle sans domaine n'a pas accès à l'écran. */
    private function domaineTraite(): DomaineReclamation
    {
        $this->exiger(self::PERMISSION);
        $domaine = VieInternat::domaineDuRole($this->utilisateur()->roleCode());
        abort_if($domaine === null, 403);

        return $domaine;
    }

    /** Le chef de pôle suit tous les domaines de son périmètre ; les autres rôles, le leur. */
    private function suitTousLesDomaines(): bool
    {
        return $this->utilisateur()->aLeRole(RoleCode::ChefPole);
    }

    /** Réclamation que le compte peut traiter : de son périmètre et de son domaine. */
    private function reclamation(int $id): Reclamation
    {
        $reclamation = Reclamation::findOrFail($id);
        $this->autoriser($reclamation, self::PERMISSION);
        abort_if($reclamation->domaine !== $this->domaineTraite(), 403, 'Cette réclamation relève d\'un autre responsable.');

        return $reclamation;
    }

    /** Réclamation que le compte peut consulter : son domaine, ou tout domaine pour le chef de pôle. */
    private function reclamationConsultable(int $id): Reclamation
    {
        $reclamation = Reclamation::findOrFail($id);
        $this->autoriser($reclamation, self::PERMISSION);
        abort_if(! $this->suitTousLesDomaines() && $reclamation->domaine !== $this->domaineTraite(), 403);

        return $reclamation;
    }

    public function prendreEnCharge(VieInternat $vie, int $id): void
    {
        $reclamation = $this->reclamation($id);

        $this->executer(fn () => $vie->traiterReclamation($reclamation, StatutReclamation::EnCours, null, $this->utilisateur()), 'Réclamation prise en charge.');
    }

    public function repondre(int $id): void
    {
        $this->ligne = $this->reclamation($id)->id;
        $this->reponse = '';
        $this->ouvrirDialogue('reponse');
    }

    public function clore(VieInternat $vie): void
    {
        $reclamation = $this->reclamation((int) $this->ligne);
        $reponse = $this->validate(['reponse' => ['required', 'string', 'min:5', 'max:2000']], attributes: ['reponse' => 'réponse'])['reponse'];

        if ($this->executer(fn () => $vie->traiterReclamation($reclamation, StatutReclamation::Traitee, trim($reponse), $this->utilisateur()), 'Réponse envoyée au stagiaire.')) {
            $this->fermerDialogue();
        }
    }

    /** Historique d'une réclamation : dépôt, changements d'état et réponse, même après la clôture. */
    public function voirHistorique(int $id): void
    {
        $this->ligne = $this->reclamationConsultable($id)->id;
        $this->ouvrirDialogue('historique');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
        $this->resetValidation();
    }

    /** @return list<array{date: mixed, acteur: string, evenement: string, texte: ?string}> */
    private function historique(Reclamation $reclamation): array
    {
        $etapes = [[
            'date' => $reclamation->date_creation, 'acteur' => $reclamation->stagiaire->nomComplet(),
            'evenement' => 'Réclamation déposée', 'texte' => $reclamation->description,
        ]];

        $traces = JournalAudit::with('utilisateur:id,nom,prenom')
            ->where('entite', 'reclamation')->where('entite_id', $reclamation->id)->where('action', 'reclamation.traitement')
            ->orderBy('date_heure')->orderBy('id')->get();

        foreach ($traces as $trace) {
            $statut = StatutReclamation::tryFrom((string) ($trace->details['statut'] ?? ''));
            $finale = $statut === StatutReclamation::Traitee;
            $etapes[] = [
                'date' => $trace->date_heure, 'acteur' => $trace->utilisateur?->nomComplet() ?? '—',
                'evenement' => $finale ? 'Réponse envoyée, réclamation traitée et clôturée' : 'Passée à l’état « '.($statut?->libelle() ?? '—').' »',
                'texte' => $finale ? $reclamation->reponse : null,
            ];
        }

        return $etapes;
    }

    public function render()
    {
        $domaineTraite = $this->domaineTraite();
        $tous = $this->suitTousLesDomaines();
        $domaineFiltre = $tous ? DomaineReclamation::tryFrom($this->domaine) : $domaineTraite;
        $terme = trim($this->stagiaire);
        $motif = addcslashes($terme, '%_\\').'%';

        $reclamations = Reclamation::visiblesPar($this->utilisateur())
            ->when($domaineFiltre !== null, fn (Builder $q) => $q->where('domaine', $domaineFiltre->value))
            ->when(StatutReclamation::tryFrom($this->statut), fn (Builder $q, StatutReclamation $s) => $q->where('statut', $s->value))
            ->when($terme !== '', fn (Builder $q) => $q->whereHas('stagiaire', fn (Builder $s) => $s
                ->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif)))
            ->with('stagiaire:id,nom,prenom,cef', 'internat:id,nom', 'traitant:id,nom,prenom')
            ->orderByRaw('statut = ? ASC', [StatutReclamation::Traitee->value])->orderByDesc('date_creation')
            ->paginate(25);

        $ouverte = $this->ligne !== null && in_array($this->dialogue, ['reponse', 'historique'], true)
            ? Reclamation::with('stagiaire:id,nom,prenom,cef', 'internat:id,nom', 'traitant:id,nom,prenom')->find($this->ligne)
            : null;

        return view('livewire.personnel.reclamations', [
            'domaineTraite' => $domaineTraite,
            'tous' => $tous,
            'statuts' => [
                StatutReclamation::Ouverte->value => 'En attente',
                StatutReclamation::EnCours->value => 'En cours de traitement',
                StatutReclamation::Traitee->value => 'Traitées / clôturées',
            ],
            'domaines' => DomaineReclamation::options(),
            'reclamations' => $reclamations,
            'filtresActifs' => $this->statut !== '' || $this->domaine !== '' || $terme !== '',
            'enReponse' => $this->dialogue === 'reponse' ? $ouverte : null,
            'enHistorique' => $this->dialogue === 'historique' ? $ouverte : null,
            'historique' => $this->dialogue === 'historique' && $ouverte ? $this->historique($ouverte) : [],
        ]);
    }
}
