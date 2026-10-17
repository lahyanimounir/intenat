<?php

namespace App\Domain\Reporting;

use App\Models\Affectation;
use App\Models\Demande;
use App\Models\Paiement;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents administratifs individuels, produits sur un gabarit commun :
 * institution, établissement, titre, référence, date d'édition, pagination.
 * Aucun logo n'est utilisé tant qu'une ressource graphique officielle n'est pas fournie.
 */
class DocumentsAdministratifs
{
    public function accuseDeReception(Demande $demande): Response
    {
        $demande->loadMissing('stagiaire', 'campagne.internat.etablissement', 'pieces.typePiece');

        return $this->rendre("accuse-{$demande->numero_dossier}.pdf", [
            'titre' => 'Accusé de réception',
            'sousTitre' => 'Demande de place à l\'internat — campagne '.$demande->campagne->annee,
            'reference' => $demande->numero_dossier,
            'etablissement' => $demande->campagne->internat->etablissement->nom_efp,
            'blocs' => [
                ['titre' => 'Dossier', 'lignes' => [
                    'Numéro de dossier' => $demande->numero_dossier,
                    'Déposé le' => $demande->date_soumission?->format('d/m/Y à H:i'),
                    'Internat' => $demande->campagne->internat->nom,
                    'Statut à l\'édition' => $demande->statut->libelle(),
                ]],
                ['titre' => 'Stagiaire', 'lignes' => [
                    'Nom et prénom' => $demande->stagiaire->nom.' '.$demande->stagiaire->prenom,
                    'CEF' => $demande->stagiaire->cef,
                    'Filière' => $demande->stagiaire->nom_filiere,
                ]],
                ['titre' => 'Pièces déposées', 'lignes' => $demande->pieces
                    ->mapWithKeys(fn ($p) => [$p->typePiece->libelle => 'Déposée le '.$p->date_depot->format('d/m/Y')])->all()],
            ],
            'paragraphes' => [
                'Le présent accusé atteste du dépôt du dossier dans les délais de la campagne. Il ne vaut ni admission ni attribution d\'une place.',
            ],
        ]);
    }

    public function autorisationDePaiement(Paiement $paiement): Response
    {
        $paiement->loadMissing('demande.stagiaire', 'demande.campagne.internat.etablissement', 'autorisateur');
        $demande = $paiement->demande;

        return $this->rendre("autorisation-paiement-{$demande->numero_dossier}.pdf", [
            'titre' => 'Autorisation de paiement',
            'sousTitre' => 'Frais d\'internat de la campagne',
            'reference' => 'AP-'.str_pad((string) $paiement->id, 6, '0', STR_PAD_LEFT),
            'etablissement' => $demande->campagne->internat->etablissement->nom_efp,
            'blocs' => [
                ['titre' => 'Bénéficiaire', 'lignes' => [
                    'Nom et prénom' => $demande->stagiaire->nom.' '.$demande->stagiaire->prenom,
                    'CEF' => $demande->stagiaire->cef,
                    'Numéro de dossier' => $demande->numero_dossier,
                    'Internat' => $demande->campagne->internat->nom,
                ]],
                ['titre' => 'Paiement autorisé', 'lignes' => [
                    'Montant dû' => number_format((float) $paiement->montant, 2, ',', ' ').' DH',
                    'Autorisé le' => $paiement->date_autorisation?->format('d/m/Y'),
                    'Autorisé par' => $paiement->autorisateur?->nomComplet(),
                ]],
            ],
            'paragraphes' => [
                'Le stagiaire règle le montant indiqué selon les modalités de l\'établissement, puis téléverse son reçu dans son espace INTERNAT.',
            ],
            'signataire' => 'Le chef de pôle scolarité',
        ]);
    }

    public function attestationAffectation(Affectation $affectation): Response
    {
        $affectation->loadMissing('demande.stagiaire', 'lit.chambre.batiment.internat.etablissement', 'affecteur');
        $demande = $affectation->demande;
        $chambre = $affectation->lit->chambre;

        return $this->rendre("hebergement-{$demande->numero_dossier}.pdf", [
            'titre' => 'Autorisation d\'hébergement',
            'sousTitre' => $chambre->batiment->internat->nom,
            'reference' => 'AH-'.str_pad((string) $affectation->id, 6, '0', STR_PAD_LEFT),
            'etablissement' => $chambre->batiment->internat->etablissement->nom_efp,
            'blocs' => [
                ['titre' => 'Résident', 'lignes' => [
                    'Nom et prénom' => $demande->stagiaire->nom.' '.$demande->stagiaire->prenom,
                    'CEF' => $demande->stagiaire->cef,
                    'Numéro de dossier' => $demande->numero_dossier,
                ]],
                ['titre' => 'Place attribuée', 'lignes' => [
                    'Bâtiment' => $chambre->batiment->nom,
                    'Chambre' => $chambre->numero.' (étage '.$chambre->etage.')',
                    'Lit' => $affectation->lit->numero,
                    'À compter du' => $affectation->date_debut->format('d/m/Y'),
                    'Occupation autorisée le' => $demande->occupation_autorisee_le?->format('d/m/Y'),
                    'Reçu de paiement' => $demande->occupation_sans_recu ? 'À déposer (hébergement autorisé sans reçu)' : 'Validé',
                ]],
            ],
            'signataire' => 'Le gestionnaire de l\'internat',
        ]);
    }

    /** @param array<string, mixed> $donnees */
    private function rendre(string $nom, array $donnees): Response
    {
        return Pdf::loadView('pdf.document', $donnees)->setPaper('a4')->stream($nom);
    }
}
