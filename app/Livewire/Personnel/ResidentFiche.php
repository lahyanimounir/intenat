<?php

namespace App\Livewire\Personnel;

use App\Domain\Accommodation\AffectationLits;
use App\Enums\TypeMouvement;
use App\Livewire\Concerns\AvecChoixDeLit;
use App\Livewire\PagePersonnel;
use App\Models\Affectation;
use App\Models\Lit;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Fiche d'un résident : son séjour, son lit, ses mouvements. Le gestionnaire y
 * enregistre l'arrivée, un changement de lit et la fin du séjour — sans jamais
 * effacer une période passée.
 */
#[Title('Résident')]
class ResidentFiche extends PagePersonnel
{
    use AvecChoixDeLit;

    private const FINS = [TypeMouvement::Depart, TypeMouvement::Exclusion, TypeMouvement::NonPresente];

    #[Locked]
    public int $affectationId;

    public string $lit = '';

    public string $motif = '';

    public string $type = 'depart';

    public function mount(Affectation $affectation): void
    {
        $this->autoriser($affectation, 'hebergement.consulter');
        $this->affectationId = $affectation->id;
    }

    private function affectation(?string $permission = null): Affectation
    {
        $affectation = Affectation::findOrFail($this->affectationId);
        $this->autoriser($affectation, $permission ?? 'hebergement.consulter');

        return $affectation;
    }

    public function enregistrerArrivee(AffectationLits $affectations): void
    {
        $affectation = $this->affectation('hebergement.gerer');

        $this->executer(fn () => $affectations->enregistrerArrivee($affectation, $this->utilisateur()), 'Arrivée enregistrée.');
    }

    public function preparer(string $fenetre): void
    {
        $this->affectation('hebergement.gerer');
        abort_unless(in_array($fenetre, ['lit', 'fin'], true), 404);

        $this->reset('lit', 'motif');
        $this->type = TypeMouvement::Depart->value;
        $this->ouvrirDialogue($fenetre);
    }

    public function changerDeLit(AffectationLits $affectations): void
    {
        $affectation = $this->affectation('hebergement.gerer');

        $valides = $this->validate([
            'lit' => ['required', 'integer'],
            'motif' => ['required', 'string', 'min:5', 'max:500'],
        ], ['lit.required' => 'Choisissez le nouveau lit.'], ['lit' => 'lit', 'motif' => 'motif du changement']);

        $lit = Lit::visiblesPar($this->utilisateur())->findOrFail((int) $valides['lit']);
        $nouvelle = null;

        if ($this->executer(function () use ($affectations, $affectation, $lit, $valides, &$nouvelle) {
            $nouvelle = $affectations->changerDeLit($affectation, $lit, $valides['motif'], $this->utilisateur());
        })) {
            // Le changement clôt la période en cours et en ouvre une nouvelle : la fiche suit le nouveau lit.
            session()->flash('succes', 'Changement de lit enregistré. La période précédente est conservée.');
            $this->redirect(route('hebergement.residents.fiche', $nouvelle), navigate: true);
        }
    }

    public function cloturer(AffectationLits $affectations): void
    {
        $affectation = $this->affectation('hebergement.gerer');

        $valides = $this->validate([
            'type' => ['required', Rule::in(array_map(fn (TypeMouvement $t) => $t->value, self::FINS))],
            'motif' => ['required', 'string', 'min:5', 'max:1000'],
        ], attributes: ['type' => 'type de fin de séjour', 'motif' => 'motif']);

        if ($this->executer(
            fn () => $affectations->cloturer($affectation, TypeMouvement::from($valides['type']), $valides['motif'], $this->utilisateur()),
            'Séjour clos, lit libéré. Le chef de pôle est alerté de la place libre.',
        )) {
            $this->fermerDialogue();
        }
    }

    public function fermerDialogue(): void
    {
        $this->dialogue = null;
        $this->resetValidation();
    }

    public function render()
    {
        $affectation = $this->affectation()->load(
            'demande.stagiaire.etablissement', 'demande.campagne.internat', 'demande.paiements', 'lit.chambre.batiment',
            'mouvements.auteur:id,nom,prenom', 'sorties', 'absences', 'incidents',
        );
        $utilisateur = $this->utilisateur();
        $internatId = $affectation->demande->campagne->internat_id;

        return view('livewire.personnel.resident-fiche', [
            'affectation' => $affectation,
            'stagiaire' => $affectation->demande->stagiaire,
            'arrivee' => $affectation->mouvements->first(fn ($m) => $m->type === TypeMouvement::Arrivee),
            'gerer' => $affectation->estActive() && $utilisateur->can('hebergement.gerer'),
            'historiqueLits' => Affectation::where('demande_id', $affectation->demande_id)->with('lit.chambre')->orderByDesc('date_debut')->orderByDesc('id')->get(),
            'litsLibres' => $this->dialogue === 'lit' ? $this->litsAffectables($internatId) : collect(),
            'fins' => collect(self::FINS)->mapWithKeys(fn (TypeMouvement $t) => [$t->value => $t->libelle()]),
        ]);
    }
}
