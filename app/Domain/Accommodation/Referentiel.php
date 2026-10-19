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
use Illuminate\Support\Facades\DB;

/**
 * Référentiel d'un internat — bâtiments, étages, chambres, lits — tenu à la main
 * par son gestionnaire, en complément de l'import CSV. Chaque écriture est
 * journalisée ; aucune ne supprime l'historique d'occupation d'un lit ni celui de
 * la maintenance : une structure qui en porte se met hors service, elle ne se
 * supprime pas.
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

    /** Renomme un lit (son numéro), unique dans sa chambre. */
    public function renommerLit(Lit $lit, string $numero): Lit
    {
        if ($lit->chambre->lits()->where('numero', $numero)->whereKeyNot($lit->id)->exists()) {
            throw new RegleMetierException('Un lit porte déjà ce numéro dans cette chambre.', 'lit_en_double');
        }

        $avant = $lit->numero;
        $lit->forceFill(['numero' => $numero])->save();
        $this->journal->consigner('lit.modification', $lit, details: ['avant' => $avant, 'apres' => $numero]);

        return $lit;
    }

    public function supprimerLit(Lit $lit): void
    {
        DB::transaction(function () use ($lit) {
            $lit = Lit::with('chambre')->lockForUpdate()->findOrFail($lit->id);
            $this->exigerLitSupprimable($lit);
            $chambre = $lit->chambre;

            $this->journal->consigner('lit.suppression', $lit, details: ['chambre' => $chambre->numero, 'numero' => $lit->numero]);
            $lit->delete();
            $this->affectations->synchroniserChambre($chambre->fresh());
        });
    }

    public function supprimerChambre(Chambre $chambre): void
    {
        DB::transaction(function () use ($chambre) {
            $chambre = Chambre::with('lits', 'batiment')->lockForUpdate()->findOrFail($chambre->id);
            $this->exigerChambreSupprimable($chambre);
            $this->retirerChambre($chambre);
        });
    }

    /** Ajoute un étage au sommet du bâtiment. */
    public function ajouterEtage(Batiment $batiment): Batiment
    {
        if ($batiment->nombre_etages >= 30) {
            throw new RegleMetierException('Un bâtiment compte au plus 30 étages.', 'etages_maximum');
        }

        $batiment->forceFill(['nombre_etages' => $batiment->nombre_etages + 1])->save();
        $this->journal->consigner('batiment.etage_ajoute', $batiment, details: ['etage' => $batiment->nombre_etages]);

        return $batiment;
    }

    /** Renumérote un étage : ses chambres passent à un autre étage du bâtiment, qui doit être vide. */
    public function renumeroterEtage(Batiment $batiment, int $etage, int $nouveau): Batiment
    {
        if ($nouveau < 0 || $nouveau > $batiment->nombre_etages) {
            throw new RegleMetierException("Le bâtiment compte les étages 0 à {$batiment->nombre_etages}.", 'etage_inexistant');
        }

        if ($nouveau === $etage) {
            return $batiment;
        }

        if ($batiment->chambres()->where('etage', $nouveau)->exists()) {
            throw new RegleMetierException('Cet étage porte déjà des chambres : choisissez un étage vide.', 'etage_occupe');
        }

        $deplacees = $batiment->chambres()->where('etage', $etage)->update(['etage' => $nouveau]);
        $this->journal->consigner('batiment.etage_renumerote', $batiment, details: ['avant' => $etage, 'apres' => $nouveau, 'chambres' => $deplacees]);

        return $batiment;
    }

    /**
     * Supprime un étage : ses chambres et leurs lits sont supprimés. L'étage du sommet disparaît du
     * bâtiment ; un étage intermédiaire reste comme niveau vide (un niveau ne s'ôte pas du milieu).
     */
    public function supprimerEtage(Batiment $batiment, int $etage): Batiment
    {
        return DB::transaction(function () use ($batiment, $etage) {
            $batiment = Batiment::lockForUpdate()->findOrFail($batiment->id);
            $chambres = Chambre::with('lits', 'batiment')->where('batiment_id', $batiment->id)->where('etage', $etage)->lockForUpdate()->get();

            foreach ($chambres as $chambre) {
                $this->exigerChambreSupprimable($chambre);
            }
            foreach ($chambres as $chambre) {
                $this->retirerChambre($chambre);
            }

            if ($etage === $batiment->nombre_etages && $etage > 0) {
                $batiment->forceFill(['nombre_etages' => $batiment->nombre_etages - 1])->save();
            }

            $this->journal->consigner('batiment.etage_supprime', $batiment, details: ['etage' => $etage, 'chambres' => $chambres->count()]);

            return $batiment;
        });
    }

    /** Supprime un bâtiment avec ses étages, chambres et lits, s'ils ne portent aucun historique. */
    public function supprimerBatiment(Batiment $batiment): void
    {
        DB::transaction(function () use ($batiment) {
            $batiment = Batiment::lockForUpdate()->findOrFail($batiment->id);
            $chambres = Chambre::with('lits', 'batiment')->where('batiment_id', $batiment->id)->lockForUpdate()->get();

            foreach ($chambres as $chambre) {
                $this->exigerChambreSupprimable($chambre);
            }
            foreach ($chambres as $chambre) {
                $this->retirerChambre($chambre);
            }

            $this->journal->consigner('batiment.suppression', $batiment, details: ['code' => $batiment->code, 'nom' => $batiment->nom, 'chambres' => $chambres->count()]);
            $batiment->delete();
        });
    }

    private function exigerLitSupprimable(Lit $lit): void
    {
        if ($lit->affectations()->exists()) {
            throw new RegleMetierException(
                "Le lit {$lit->numero} de la chambre {$lit->chambre->numero} a déjà été attribué : son historique d'occupation est conservé. Mettez-le hors service plutôt que de le supprimer.",
                'lit_avec_historique',
            );
        }
    }

    private function exigerChambreSupprimable(Chambre $chambre): void
    {
        foreach ($chambre->lits as $lit) {
            $lit->setRelation('chambre', $chambre);
            $this->exigerLitSupprimable($lit);
        }

        if ($chambre->demandesMaintenance()->exists()) {
            throw new RegleMetierException(
                "La chambre {$chambre->numero} a un historique de maintenance, qui est conservé : elle ne peut pas être supprimée.",
                'chambre_avec_historique',
            );
        }
    }

    private function retirerChambre(Chambre $chambre): void
    {
        $this->journal->consigner('chambre.suppression', $chambre, details: [
            'batiment' => $chambre->batiment->code, 'numero' => $chambre->numero, 'etage' => $chambre->etage, 'lits' => $chambre->lits->count(),
        ]);
        Lit::where('chambre_id', $chambre->id)->delete();
        $chambre->delete();
    }
}
