<?php

namespace App\Livewire\Concerns;

use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutLit;
use App\Models\Lit;
use Illuminate\Support\Collection;

/** Liste des lits qu'un gestionnaire peut attribuer dans un internat de son périmètre. */
trait AvecChoixDeLit
{
    /**
     * Lits disponibles d'un internat, hors chambres en maintenance et bâtiments fermés, groupés par chambre.
     *
     * @return Collection<string, Collection<int, Lit>> « Bâtiment A · chambre 101 (Standard) » => lits
     */
    protected function litsAffectables(int $internatId): Collection
    {
        return Lit::visiblesPar($this->utilisateur())
            ->where('statut', StatutLit::Disponible->value)
            ->whereDoesntHave('affectationActive')
            ->whereHas('chambre', fn ($c) => $c->where('statut', '!=', StatutChambre::EnMaintenance->value)
                ->whereHas('batiment', fn ($b) => $b->where('internat_id', $internatId)->where('statut', StatutBatiment::Ouvert->value)))
            ->with('chambre.batiment')
            ->get()
            ->sortBy(fn (Lit $l) => [$l->chambre->batiment->code, $l->chambre->etage, $l->chambre->numero, $l->numero])
            ->groupBy(fn (Lit $l) => 'Bâtiment '.$l->chambre->batiment->code.' · chambre '.$l->chambre->numero.' ('.$l->chambre->type_chambre->libelle().')');
    }
}
