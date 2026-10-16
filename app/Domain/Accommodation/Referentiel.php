<?php

namespace App\Domain\Accommodation;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutChambre;
use App\Enums\StatutLit;
use App\Enums\TypeMouvement;
use App\Models\Batiment;
use App\Models\Chambre;
use App\Models\Internat;
use App\Models\Lit;

/**
 * Référentiel d'un internat — bâtiments, chambres, lits — tenu à la main par
 * son gestionnaire, en complément de l'import CSV. Chaque écriture est
 * journalisée ; aucune ne supprime l'historique d'occupation d'un lit.
 */
class Referentiel
{
    public function __construct(
        private readonly Journal $journal,
        private readonly AffectationLits $affectations,
    ) {}

    /**
     * État affiché d'un lit. « Réservé » n'est pas un statut stocké : c'est un lit
     * attribué dont l'occupant n'est pas encore arrivé.
     */
    public static function etatLit(Lit $lit, Chambre $chambre): string
    {
        if ($lit->statut === StatutLit::HorsService) {
            return 'hors_service';
        }

        if ($lit->affectationActive !== null) {
            $arrive = $lit->affectationActive->mouvements->contains(fn ($m) => $m->type === TypeMouvement::Arrivee);

            return $arrive ? 'occupe' : 'reserve';
        }

        return $chambre->statut === StatutChambre::EnMaintenance ? 'en_maintenance' : 'disponible';
    }

    /** @param  array{code: string, nom: string, nombre_etages: int, statut: string, equipements_communs: ?string}  $donnees */
    public function enregistrerBatiment(Internat $internat, ?Batiment $batiment, array $donnees): Batiment
    {
        abort_if($batiment !== null && $batiment->internat_id !== $internat->id, 404);

        $doublon = Batiment::where('internat_id', $internat->id)->where('code', $donnees['code'])
            ->when($batiment, fn ($q) => $q->whereKeyNot($batiment->id))->exists();

        if ($doublon) {
            throw new RegleMetierException('Un bâtiment porte déjà ce code dans cet internat.', 'batiment_en_double');
        }

        if ($batiment !== null && $batiment->chambres()->where('etage', '>', $donnees['nombre_etages'])->exists()) {
            throw new RegleMetierException('Des chambres existent au-dessus de ce nombre d\'étages.', 'etages_incoherents');
        }

        $batiment ??= new Batiment(['internat_id' => $internat->id]);
        $creation = ! $batiment->exists;
        $batiment->fill($donnees)->save();
        $this->journal->consigner($creation ? 'batiment.creation' : 'batiment.modification', $batiment, details: $creation ? $donnees : $this->journal->differences($batiment));

        return $batiment;
    }

    /** @param  array{numero: string, etage: int, type_chambre: string, capacite: int, equipements: ?string}  $donnees */
    public function enregistrerChambre(Batiment $batiment, ?Chambre $chambre, array $donnees): Chambre
    {
        abort_if($chambre !== null && $chambre->batiment_id !== $batiment->id, 404);

        if ($donnees['etage'] > $batiment->nombre_etages) {
            throw new RegleMetierException('L\'étage dépasse le nombre d\'étages du bâtiment.', 'etage_inexistant');
        }

        $doublon = Chambre::where('batiment_id', $batiment->id)->where('numero', $donnees['numero'])
            ->when($chambre, fn ($q) => $q->whereKeyNot($chambre->id))->exists();

        if ($doublon) {
            throw new RegleMetierException('Une chambre porte déjà ce numéro dans ce bâtiment.', 'chambre_en_double');
        }

        if ($chambre !== null && $chambre->lits()->count() > $donnees['capacite']) {
            throw new RegleMetierException('La chambre compte déjà plus de lits que cette capacité.', 'capacite_insuffisante');
        }

        $chambre ??= new Chambre(['batiment_id' => $batiment->id, 'statut' => StatutChambre::Disponible]);
        $creation = ! $chambre->exists;
        $chambre->fill($donnees)->save();
        $this->journal->consigner($creation ? 'chambre.creation' : 'chambre.modification', $chambre, details: $creation ? $donnees : $this->journal->differences($chambre));

        return $chambre;
    }

    public function ajouterLit(Chambre $chambre, string $numero): Lit
    {
        if ($chambre->lits()->where('numero', $numero)->exists()) {
            throw new RegleMetierException('Un lit porte déjà ce numéro dans cette chambre.', 'lit_en_double');
        }

        if ($chambre->lits()->count() >= $chambre->capacite) {
            throw new RegleMetierException('La capacité de la chambre est atteinte : augmentez-la avant d\'ajouter un lit.', 'capacite_atteinte');
        }

        $lit = Lit::create(['chambre_id' => $chambre->id, 'numero' => $numero, 'statut' => StatutLit::Disponible]);
        $this->journal->consigner('lit.creation', $lit, details: ['chambre' => $chambre->numero, 'numero' => $lit->numero]);

        return $lit;
    }

    /** Met un lit hors service ou le remet en service. Un lit occupé ne peut pas être désactivé. */
    public function basculerLit(Lit $lit, bool $horsService): Lit
    {
        if ($horsService && ($lit->statut === StatutLit::Occupe || $lit->affectationActive()->exists())) {
            throw new RegleMetierException('Ce lit est occupé : déplacez d\'abord son occupant.', 'lit_occupe');
        }

        $avant = $lit->statut->value;
        $lit->forceFill(['statut' => $horsService ? StatutLit::HorsService : StatutLit::Disponible])->save();
        $this->affectations->synchroniserChambre($lit->chambre);
        $this->journal->consigner('lit.statut', $lit, details: ['avant' => $avant, 'apres' => $lit->statut->value]);

        return $lit;
    }
}
