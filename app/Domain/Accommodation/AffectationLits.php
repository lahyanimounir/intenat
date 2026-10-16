<?php

namespace App\Domain\Accommodation;

use App\Domain\Admissions\ListeAttente;
use App\Domain\Applications\TransitionDemande;
use App\Domain\Audit\Journal;
use App\Domain\Identity\Perimetre;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutBatiment;
use App\Enums\StatutChambre;
use App\Enums\StatutDemande;
use App\Enums\StatutLit;
use App\Enums\StatutPaiement;
use App\Enums\TypeChambre;
use App\Enums\TypeMouvement;
use App\Enums\TypeNotification;
use App\Models\Affectation;
use App\Models\Chambre;
use App\Models\Demande;
use App\Models\Lit;
use App\Models\Mouvement;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Affectation des lits et cycle du séjour.
 *
 * Cohérence — un lit ne peut pas avoir deux occupants actifs :
 *  1. chaque opération s'exécute dans une transaction qui verrouille d'abord le
 *     dossier, puis les lits (SELECT … FOR UPDATE, toujours dans le même ordre) ;
 *  2. la base porte un index unique sur l'affectation active d'un lit et d'un
 *     dossier : même en cas de défaut applicatif, le doublon est refusé.
 * L'historique n'est jamais écrasé : un changement de lit clôt la période
 * précédente et en ouvre une nouvelle.
 */
class AffectationLits
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly TransitionDemande $transition,
        private readonly ListeAttente $listeAttente,
        private readonly Parametres $parametres,
        private readonly Notifieur $notifieur,
        private readonly Journal $journal,
    ) {}

    /**
     * Ce qui manque encore pour autoriser l'occupation, selon les règles configurées.
     *
     * @return list<string>
     */
    public function obstaclesOccupation(Demande $demande): array
    {
        $obstacles = [];
        $paiements = $demande->paiements()->get();

        if ($demande->statut !== StatutDemande::Confirme) {
            $obstacles[] = 'Le candidat n\'a pas confirmé sa place.';
        }

        $autorise = $paiements->contains(fn ($p) => in_array($p->statut, [
            StatutPaiement::Autorise, StatutPaiement::RecuDepose, StatutPaiement::Valide,
        ], true));

        if ($this->parametres->vrai('occupation.exiger_autorisation_paiement') && ! $autorise) {
            $obstacles[] = 'L\'autorisation de paiement n\'a pas été délivrée par le chef de pôle.';
        }

        if ($this->parametres->vrai('occupation.exiger_recu_valide')
            && ! $paiements->contains(fn ($p) => $p->statut === StatutPaiement::Valide)) {
            $obstacles[] = 'Aucun reçu de paiement validé.';
        }

        if ($this->parametres->vrai('occupation.exiger_dossier_physique') && ! $demande->dossier_physique_depose) {
            $obstacles[] = 'Le dossier physique n\'a pas été déposé.';
        }

        return $obstacles;
    }

    /**
     * Permission d'occuper : décision propre du gestionnaire, distincte du paiement.
     * Elle peut être accordée sans reçu (paiement non bloquant) ; ce fait est tracé.
     */
    public function autoriserOccupation(Demande $demande, Utilisateur $gestionnaire): Demande
    {
        return DB::transaction(function () use ($demande, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($demande->id);
            $this->perimetre->exigerInternat($gestionnaire, $demande->internatId());

            if ($demande->occupationAutorisee()) {
                throw RegleMetierException::conflit('L\'occupation est déjà autorisée pour ce dossier.');
            }

            $obstacles = $this->obstaclesOccupation($demande);

            if ($obstacles !== []) {
                throw new RegleMetierException(implode(' ', $obstacles), 'occupation_non_autorisable', ['obstacles' => $obstacles]);
            }

            $sansRecu = ! $demande->paiements()->where('statut', StatutPaiement::Valide->value)->exists();

            $demande->forceFill([
                'occupation_autorisee_le' => now(),
                'occupation_autorisee_par' => $gestionnaire->id,
                'occupation_sans_recu' => $sansRecu,
            ])->save();

            $this->journal->consigner('hebergement.occupation_autorisee', $demande, details: ['sans_recu' => $sansRecu]);

            return $demande;
        });
    }

    public function affecter(Demande $demande, Lit $lit, CarbonInterface $dateDebut, Utilisateur $gestionnaire, ?string $motifExceptionnel = null): Affectation
    {
        try {
            return DB::transaction(function () use ($demande, $lit, $dateDebut, $gestionnaire, $motifExceptionnel) {
                $demande = Demande::lockForUpdate()->findOrFail($demande->id);
                $lit = Lit::lockForUpdate()->findOrFail($lit->id);

                $this->exigerCandidatAffectable($demande);
                $chambre = $this->exigerLitAffectable($lit, $demande->internatId(), $gestionnaire, $motifExceptionnel);

                if ($demande->affectationActive()->exists()) {
                    throw RegleMetierException::conflit('Ce stagiaire occupe déjà un lit. Utilisez le changement de lit.', 'deja_affecte');
                }

                $affectation = $this->creerAffectation($demande, $lit, $chambre, $dateDebut, $gestionnaire, $motifExceptionnel);

                $this->journal->consigner('hebergement.affectation', $affectation, details: [
                    'demande_id' => $demande->id, 'lit_id' => $lit->id, 'chambre' => $chambre->numero,
                ]);

                $this->notifieur->versStagiaire(
                    $demande->stagiaire,
                    TypeNotification::Affectation,
                    'Chambre attribuée',
                    "Une place vous est attribuée : chambre {$chambre->numero}, lit {$lit->numero}, à compter du {$dateDebut->translatedFormat('j F Y')}.",
                    '/stagiaire/hebergement',
                    "affectation:{$affectation->id}",
                );

                return $affectation;
            });
        } catch (UniqueConstraintViolationException) {
            // Garde-fou de la base : une autre session a pris ce lit entre-temps.
            throw RegleMetierException::conflit('Ce lit vient d\'être attribué à un autre stagiaire.', 'lit_deja_attribue');
        } catch (QueryException $e) {
            throw $this->traduireVerrou($e);
        }
    }

    /** Clôt la période en cours et en ouvre une nouvelle sur un autre lit. */
    public function changerDeLit(Affectation $affectation, Lit $nouveauLit, string $motif, Utilisateur $gestionnaire): Affectation
    {
        try {
            return DB::transaction(function () use ($affectation, $nouveauLit, $motif, $gestionnaire) {
                $demande = Demande::lockForUpdate()->findOrFail($affectation->demande_id);

                // Verrouillage des deux lits dans l'ordre des identifiants : pas d'interblocage.
                $lits = Lit::whereIn('id', [$affectation->lit_id, $nouveauLit->id])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $affectation = Affectation::lockForUpdate()->findOrFail($affectation->id);

                if (! $affectation->estActive()) {
                    throw new RegleMetierException('Ce séjour est déjà clos.', 'sejour_clos');
                }

                if ($affectation->lit_id === $nouveauLit->id) {
                    throw new RegleMetierException('Le stagiaire occupe déjà ce lit.', 'meme_lit');
                }

                $this->perimetre->exigerInternat($gestionnaire, $demande->internatId());
                $nouveau = $lits[$nouveauLit->id];
                $chambre = $this->exigerLitAffectable($nouveau, $demande->internatId(), $gestionnaire, $motif);

                $aujourdhui = now()->startOfDay();
                $affectation->forceFill(['date_fin' => $aujourdhui, 'cloture_par' => $gestionnaire->id])->save();
                $this->libererLit($lits[$affectation->lit_id]);

                $nouvelle = $this->creerAffectation($demande, $nouveau, $chambre, $aujourdhui, $gestionnaire, null);

                // Un résident déjà arrivé reste « arrivé » sur son nouveau lit.
                Mouvement::create([
                    'affectation_id' => $nouvelle->id,
                    'type' => TypeMouvement::ChangementChambre,
                    'date_mouvement' => now(),
                    'motif' => $motif,
                    'saisi_par' => $gestionnaire->id,
                ]);

                if ($affectation->arriveeEnregistree()) {
                    Mouvement::create([
                        'affectation_id' => $nouvelle->id,
                        'type' => TypeMouvement::Arrivee,
                        'date_mouvement' => now(),
                        'motif' => 'Report de l\'arrivée après changement de lit',
                        'saisi_par' => $gestionnaire->id,
                    ]);
                }

                $this->journal->consigner('hebergement.changement_lit', $nouvelle, details: [
                    'ancien_lit_id' => $affectation->lit_id, 'nouveau_lit_id' => $nouveau->id, 'motif' => $motif,
                ]);

                $this->notifieur->versStagiaire(
                    $demande->stagiaire,
                    TypeNotification::Affectation,
                    'Changement de chambre',
                    "Vous êtes désormais affecté à la chambre {$chambre->numero}, lit {$nouveau->numero}.",
                    '/stagiaire/hebergement',
                    "changement_lit:{$nouvelle->id}",
                );

                return $nouvelle;
            });
        } catch (UniqueConstraintViolationException) {
            throw RegleMetierException::conflit('Ce lit vient d\'être attribué à un autre stagiaire.', 'lit_deja_attribue');
        } catch (QueryException $e) {
            throw $this->traduireVerrou($e);
        }
    }

    public function enregistrerArrivee(Affectation $affectation, Utilisateur $gestionnaire): Mouvement
    {
        return DB::transaction(function () use ($affectation, $gestionnaire) {
            $affectation = Affectation::lockForUpdate()->findOrFail($affectation->id);

            if (! $affectation->estActive()) {
                throw new RegleMetierException('Ce séjour est déjà clos.', 'sejour_clos');
            }

            if ($affectation->arriveeEnregistree()) {
                throw RegleMetierException::conflit('L\'arrivée de ce résident est déjà enregistrée.', 'arrivee_existante');
            }

            $mouvement = Mouvement::create([
                'affectation_id' => $affectation->id,
                'type' => TypeMouvement::Arrivee,
                'date_mouvement' => now(),
                'saisi_par' => $gestionnaire->id,
            ]);

            $this->journal->consigner('hebergement.arrivee', $affectation);

            return $mouvement;
        });
    }

    /**
     * Fin du séjour : départ, exclusion ou non-présentation. Libère le lit,
     * conserve l'historique et alerte le chef de pôle de la place libérée.
     */
    public function cloturer(Affectation $affectation, TypeMouvement $type, string $motif, Utilisateur $gestionnaire): Affectation
    {
        if (! in_array($type, [TypeMouvement::Depart, TypeMouvement::Exclusion, TypeMouvement::NonPresente], true)) {
            throw new RegleMetierException('Type de fin de séjour invalide.', 'type_invalide');
        }

        return DB::transaction(function () use ($affectation, $type, $motif, $gestionnaire) {
            $demande = Demande::lockForUpdate()->findOrFail($affectation->demande_id);
            $lit = Lit::lockForUpdate()->findOrFail($affectation->lit_id);
            $affectation = Affectation::lockForUpdate()->findOrFail($affectation->id);

            if (! $affectation->estActive()) {
                throw RegleMetierException::conflit('Ce séjour est déjà clos.', 'sejour_clos');
            }

            $arrive = $affectation->arriveeEnregistree();

            if ($type === TypeMouvement::NonPresente && $arrive) {
                throw new RegleMetierException('Ce résident est déjà arrivé : enregistrez un départ ou une exclusion.', 'deja_arrive');
            }

            if ($type !== TypeMouvement::NonPresente && ! $arrive) {
                throw new RegleMetierException('L\'arrivée n\'a jamais été enregistrée : utilisez « Non présenté ».', 'jamais_arrive');
            }

            $affectation->forceFill(['date_fin' => now()->startOfDay(), 'cloture_par' => $gestionnaire->id])->save();
            $this->libererLit($lit);

            Mouvement::create([
                'affectation_id' => $affectation->id,
                'type' => $type,
                'date_mouvement' => now(),
                'motif' => $motif,
                'saisi_par' => $gestionnaire->id,
            ]);

            // Un dossier déjà « Désisté » garde son statut ; sinon le séjour est terminé.
            if ($demande->statut === StatutDemande::Confirme) {
                $this->transition->appliquer($demande, StatutDemande::SejourTermine, "{$type->libelle()} — {$motif}");
            }

            $this->journal->consigner('hebergement.fin_sejour', $affectation, details: [
                'type' => $type->value, 'motif' => $motif, 'lit_id' => $lit->id,
            ]);

            $this->listeAttente->signalerPlaceLibre($demande->campagne, "fin-sejour-{$affectation->id}");

            return $affectation;
        });
    }

    /** Recalcule « Disponible / Occupée » d'une chambre qui n'est pas en maintenance. */
    public function synchroniserChambre(Chambre $chambre): void
    {
        if ($chambre->statut === StatutChambre::EnMaintenance) {
            return;
        }

        $utilisables = $chambre->lits()->where('statut', '!=', StatutLit::HorsService->value)->count();
        $occupes = $chambre->lits()->where('statut', StatutLit::Occupe->value)->count();
        $statut = $utilisables > 0 && $occupes >= $utilisables ? StatutChambre::Occupee : StatutChambre::Disponible;

        if ($chambre->statut !== $statut) {
            $chambre->forceFill(['statut' => $statut])->save();
        }
    }

    private function exigerCandidatAffectable(Demande $demande): void
    {
        if ($demande->statut !== StatutDemande::Confirme) {
            throw new RegleMetierException(
                'Un lit ne s\'attribue qu\'à un admis validé par le chef de pôle et ayant confirmé sa place.',
                'candidat_non_autorise',
            );
        }

        if (! $demande->occupationAutorisee()) {
            throw new RegleMetierException(
                'L\'occupation n\'a pas encore été autorisée pour ce dossier.',
                'occupation_non_autorisee',
            );
        }
    }

    private function exigerLitAffectable(Lit $lit, ?int $internatDuDossier, Utilisateur $gestionnaire, ?string $motif): Chambre
    {
        $chambre = Chambre::with('batiment')->lockForUpdate()->findOrFail($lit->chambre_id);
        $internatDuLit = $chambre->batiment->internat_id;

        $this->perimetre->exigerInternat($gestionnaire, $internatDuLit);

        if ($internatDuLit !== $internatDuDossier) {
            throw new RegleMetierException(
                'Ce lit appartient à un autre internat que celui du dossier.',
                'lit_autre_internat',
            );
        }

        if ($lit->statut === StatutLit::HorsService) {
            throw new RegleMetierException('Ce lit est hors service.', 'lit_hors_service');
        }

        if ($lit->statut !== StatutLit::Disponible) {
            throw RegleMetierException::conflit('Ce lit est déjà occupé.', 'lit_occupe');
        }

        if ($chambre->statut === StatutChambre::EnMaintenance) {
            throw new RegleMetierException('Cette chambre est en maintenance : aucune affectation n\'y est possible.', 'chambre_en_maintenance');
        }

        if ($chambre->batiment->statut !== StatutBatiment::Ouvert) {
            throw new RegleMetierException('Seul un bâtiment ouvert reçoit des affectations.', 'batiment_ferme');
        }

        if (in_array($chambre->type_chambre, [TypeChambre::Isolement, TypeChambre::Infirmerie], true) && blank($motif)) {
            throw new RegleMetierException(
                'Les chambres « Isolement » et « Infirmerie » ne font pas partie des places offertes : un motif est requis.',
                'motif_requis',
            );
        }

        $occupants = Affectation::actives()->whereHas('lit', fn ($q) => $q->where('chambre_id', $chambre->id))->count();

        if ($occupants >= $chambre->capacite) {
            throw new RegleMetierException('La capacité de cette chambre est atteinte.', 'capacite_atteinte');
        }

        return $chambre;
    }

    private function creerAffectation(Demande $demande, Lit $lit, Chambre $chambre, CarbonInterface $debut, Utilisateur $gestionnaire, ?string $motif): Affectation
    {
        $affectation = Affectation::create([
            'demande_id' => $demande->id,
            'lit_id' => $lit->id,
            'date_debut' => $debut->toDateString(),
            'affecte_par' => $gestionnaire->id,
            'motif_exceptionnel' => $motif,
        ]);

        $lit->forceFill(['statut' => StatutLit::Occupe])->save();
        $this->synchroniserChambre($chambre);

        return $affectation;
    }

    private function libererLit(Lit $lit): void
    {
        if ($lit->statut === StatutLit::Occupe) {
            $lit->forceFill(['statut' => StatutLit::Disponible])->save();
        }

        $this->synchroniserChambre($lit->chambre()->first());
    }

    /** Verrou non obtenu (attente dépassée, interblocage) : message clair plutôt qu'une erreur 500. */
    private function traduireVerrou(QueryException $e): \Throwable
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return in_array($code, [1205, 1213, 3572], true)
            ? RegleMetierException::conflit('Ce lit est en cours d\'attribution par un autre utilisateur. Réessayez.', 'lit_verrouille')
            : $e;
    }
}
