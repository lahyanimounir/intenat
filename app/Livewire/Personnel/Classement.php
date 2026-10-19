<?php

namespace App\Livewire\Personnel;

use App\Domain\Admissions\CalculScore;
use App\Domain\Admissions\Classement as CalculDuClassement;
use App\Domain\Admissions\Deliberation;
use App\Domain\Admissions\NotationManuelle;
use App\Domain\Shared\Parametres;
use App\Enums\ListeAdmission;
use App\Enums\StatutDemande;
use App\Enums\TypeDecision;
use App\Livewire\Concerns\AvecDetailScore;
use App\Livewire\Concerns\AvecTableau;
use App\Livewire\PagePersonnel;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\Demande;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Classement et délibération d'une campagne.
 *
 * Le classement propose : liste principale dans la limite des places, liste
 * d'attente ensuite. Le chef de pôle peut s'écarter de la proposition, dossier
 * par dossier et avec un motif ; rien n'est décidé tant qu'il n'a pas validé
 * les admissions. Avec la campagne de l'autre internat de l'établissement, on
 * obtient les quatre listes : admis et attente, filles et garçons.
 */
#[Title('Classement et délibération')]
class Classement extends PagePersonnel
{
    use AvecDetailScore, AvecTableau;

    #[Url(except: '')]
    public string $campagne = '';

    #[Url(except: '')]
    public string $liste = '';

    /** Dossier dont le détail du score s'ouvre à l'arrivée (lien depuis la fiche d'un dossier). */
    #[Url(except: '')]
    public string $dossier = '';

    /** Écarts à la proposition, par identifiant de dossier : décision et motif. Revalidés par le domaine à la délibération. */
    public array $ajustements = [];

    public bool $arbitrage = false;

    /** Message général adressé à tous les candidats, saisi au moment de la délibération. */
    public string $messageDeliberation = '';

    #[Locked]
    public ?int $ajuste = null;

    public string $decision = '';

    public string $motif = '';

    public function mount(): void
    {
        $this->exiger('admissions.consulter', 'admissions.promouvoir');

        if ($this->dossier !== '' && ctype_digit($this->dossier)) {
            $this->voirScore((int) $this->dossier);
        }
    }

    protected function filtres(): array
    {
        return ['campagne', 'liste'];
    }

    public function updatedCampagne(): void
    {
        // Les ajustements ne valent que pour la campagne en cours d'examen.
        $this->reset('ajustements', 'arbitrage', 'liste', 'recherche', 'notes');
        $this->resetPage();
    }

    /**
     * Campagnes présentées au classement. Le gestionnaire, qui instruit les dossiers, ne voit les scores
     * qu'une fois l'instruction terminée (campagne délibérée) tant que la règle « score masqué au
     * gestionnaire » est en vigueur.
     */
    private function campagnesConsultables(): Builder
    {
        $utilisateur = $this->utilisateur();

        return Campagne::visiblesPar($utilisateur)->whereNull('archivee_le')->whereIn('statut', ['ouverte', 'cloturee'])
            ->when(! $utilisateur->can('admissions.consulter') && app(Parametres::class)->vrai('instruction.masquer_score_gestionnaire'),
                fn (Builder $q) => $q->whereNotNull('date_deliberation'));
    }

    private function campagneChoisie(): ?Campagne
    {
        $campagnes = $this->campagnesConsultables();

        $campagne = $this->campagne !== ''
            ? (clone $campagnes)->find((int) $this->campagne)
            : (clone $campagnes)->orderByDesc('annee')->orderBy('internat_id')->orderByDesc('date_ouverture')->first();

        if ($campagne !== null) {
            // Le gestionnaire consulte en lecture seule : l'internat de la campagne doit être le sien.
            $this->autoriser($campagne, $this->utilisateur()->can('admissions.consulter') ? 'admissions.consulter' : 'admissions.promouvoir');
        }

        return $campagne;
    }

    private function campagneExigee(string $permission): Campagne
    {
        $campagne = $this->campagneChoisie();
        abort_if($campagne === null, 404);
        $this->autoriser($campagne, $permission);

        return $campagne;
    }

    public function recalculer(CalculDuClassement $classement): void
    {
        $campagne = $this->campagneExigee('admissions.deliberer');

        $this->executer(function () use ($classement, $campagne) {
            $etat = $classement->recalculer($campagne);
            $this->message("Classement recalculé : {$etat['classes']} dossier(s) classé(s) pour {$etat['places']} place(s).");
        });
    }

    public function preparerAjustement(int $demande): void
    {
        $campagne = $this->campagneExigee('admissions.deliberer');
        $ligne = Demande::where('campagne_id', $campagne->id)->where('statut', StatutDemande::Complet->value)->findOrFail($demande);

        $this->ajuste = $ligne->id;
        $this->decision = $this->ajustements[$ligne->id]['decision'] ?? '';
        $this->motif = $this->ajustements[$ligne->id]['motif'] ?? '';
        $this->ouvrirDialogue('ajustement');
    }

    public function enregistrerAjustement(): void
    {
        $this->campagneExigee('admissions.deliberer');

        $valides = $this->validate([
            'decision' => ['required', Rule::enum(TypeDecision::class)],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['motif.min' => 'Le motif est obligatoire (5 caractères au moins).'], ['decision' => 'décision', 'motif' => 'motif']);

        $this->ajustements[$this->ajuste] = $valides;
        $this->fermerDialogue();
    }

    public function retirerAjustement(int $demande): void
    {
        unset($this->ajustements[$demande]);
    }

    public function deliberer(Deliberation $deliberation): void
    {
        $campagne = $this->campagneExigee('admissions.deliberer');

        $valides = $this->validate([
            'messageDeliberation' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['messageDeliberation' => 'message']);

        $ajustements = collect($this->ajustements)
            ->filter(fn ($a) => is_array($a) && in_array($a['decision'] ?? null, TypeDecision::valeurs(), true))
            ->mapWithKeys(fn (array $a, $id) => [(int) $id => ['decision' => $a['decision'], 'motif' => (string) ($a['motif'] ?? '')]])
            ->all();

        if ($this->executer(
            fn () => $deliberation->valider($campagne, $this->utilisateur(), $ajustements, $this->arbitrage, $valides['messageDeliberation']),
            'Admissions validées. Les résultats sont publiés et les candidats notifiés.',
        )) {
            $this->reset('ajustements', 'arbitrage', 'messageDeliberation');
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->ajuste = null;
        $this->scoreDe = null;
        $this->dialogue = null;
        $this->reset('notes');
        $this->resetValidation();
    }

    public function render()
    {
        $this->exiger('admissions.consulter', 'admissions.promouvoir');
        $utilisateur = $this->utilisateur();
        $campagne = $this->campagneChoisie()?->load('internat.etablissement');

        $campagnes = $this->campagnesConsultables()
            ->with('internat:id,nom')->orderByDesc('annee')->orderBy('internat_id')->orderByDesc('date_ouverture')->get()
            ->mapWithKeys(fn (Campagne $c) => [$c->id => $c->libellePeriode().' — '.$c->internat->nom]);

        if ($campagne === null) {
            return view('livewire.personnel.classement', ['choisie' => null, 'campagnes' => $campagnes, 'score' => $this->detailScore()]);
        }

        // Rattrapage : un dossier complet encore sans rang (classement resté en attente) est classé à l'affichage.
        $classement = app(CalculDuClassement::class);
        if ($classement->enRetard($campagne)) {
            $classement->recalculer($campagne);
        }

        $deliberee = $campagne->estDeliberee();
        $statuts = $deliberee
            ? [StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::EnAttente, StatutDemande::Refuse, StatutDemande::Desiste, StatutDemande::DelaiDepasse, StatutDemande::SejourTermine]
            : [StatutDemande::Complet];

        $terme = trim($this->recherche);
        $motif = '%'.addcslashes($terme, '%_\\').'%';

        $requete = Demande::where('campagne_id', $campagne->id)
            ->whereIn('statut', array_map(fn (StatutDemande $s) => $s->value, $statuts))
            ->whereNotNull('rang')
            ->with('stagiaire:id,nom,prenom,cef,nom_filiere')
            ->when(in_array($this->liste, ListeAdmission::valeurs(), true), fn (Builder $q) => $q->where('liste', $this->liste))
            ->when($terme !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('numero_dossier', 'like', $motif)
                ->orWhereHas('stagiaire', fn (Builder $st) => $st->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)->orWhere('cef', 'like', $motif))))
            ->orderBy('rang')->orderBy('date_soumission')->orderBy('id');

        $base = fn () => Demande::where('campagne_id', $campagne->id);
        $limite = $base()->where('liste', ListeAdmission::Principale->value)->min('score');
        $premierAttente = $base()->where('liste', ListeAdmission::Attente->value)->where('statut', StatutDemande::Complet->value)->max('score');
        $apercu = app(Deliberation::class)->apercu($campagne);

        return view('livewire.personnel.classement', [
            // « choisie » et non « campagne » : ce nom est déjà celui de la propriété publique reprise dans l'adresse.
            'choisie' => $campagne,
            'campagnes' => $campagnes,
            'dossiers' => $this->paginer($requete, 50),
            'apercu' => $apercu,
            'deliberee' => $deliberee,
            'scoreLimite' => $limite,
            'exAequo' => ! $deliberee && $limite !== null && $premierAttente !== null && (int) $limite === (int) $premierAttente,
            'placesLibres' => $deliberee ? $campagne->placesLibres() : null,
            'enPrincipale' => $base()->where('liste', ListeAdmission::Principale->value)->count(),
            'enAttente' => $base()->where('liste', ListeAdmission::Attente->value)->count(),
            'peutDeliberer' => $utilisateur->can('admissions.deliberer') && ! $deliberee,
            'dossierAjuste' => $this->dialogue === 'ajustement' && $this->ajuste !== null ? Demande::with('stagiaire:id,nom,prenom')->find($this->ajuste) : null,
            'score' => $this->detailScore(),
        ]);
    }
}
