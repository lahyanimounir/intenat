<?php

namespace App\Domain\Identity;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Models\Stagiaire;

/**
 * Administration des comptes des stagiaires.
 *
 * Un compte est créé par le stagiaire lui-même : l'administrateur n'en crée
 * pas, ne choisit ni ne voit aucun mot de passe. Il peut suspendre un compte,
 * le rétablir, ou renvoyer le lien de réinitialisation. Suspendre un compte
 * n'efface rien : le dossier, l'hébergement et l'historique sont conservés.
 *
 * Un compte actif ne prouve pas l'inscription en formation : celle-ci est
 * vérifiée à part (voir ControleInscription).
 */
class ComptesStagiaires
{
    public function __construct(
        private readonly Journal $journal,
        private readonly RecuperationCompte $recuperation,
    ) {}

    public function suspendre(Stagiaire $stagiaire, string $motif): Stagiaire
    {
        if ($stagiaire->etat_compte !== EtatCompte::Actif) {
            throw new RegleMetierException('Seul un compte actif peut être suspendu.', 'etat_incompatible');
        }

        $stagiaire->forceFill(['etat_compte' => EtatCompte::Suspendu, 'suspendu_le' => now(), 'motif_suspension' => $motif])->save();
        $this->journal->consigner('stagiaire.suspension', $stagiaire, details: ['motif' => $motif]);

        return $stagiaire;
    }

    public function retablir(Stagiaire $stagiaire): Stagiaire
    {
        if ($stagiaire->etat_compte !== EtatCompte::Suspendu) {
            throw new RegleMetierException('Ce compte n\'est pas suspendu.', 'etat_incompatible');
        }

        $stagiaire->forceFill(['etat_compte' => EtatCompte::Actif, 'suspendu_le' => null, 'motif_suspension' => null])->save();
        $this->journal->consigner('stagiaire.retablissement', $stagiaire);

        return $stagiaire;
    }

    /** Envoie au stagiaire le lien de réinitialisation, à usage unique. */
    public function envoyerReinitialisation(Stagiaire $stagiaire): void
    {
        if (! $stagiaire->estActif()) {
            throw new RegleMetierException('Le lien de réinitialisation n\'est envoyé qu\'à un compte actif.', 'etat_incompatible');
        }

        $this->recuperation->demander('stagiaire', $stagiaire->email);
        $this->journal->consigner('stagiaire.reinitialisation_envoyee', $stagiaire);
    }
}
