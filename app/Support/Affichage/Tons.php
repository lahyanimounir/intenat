<?php

namespace App\Support\Affichage;

use BackedEnum;

/**
 * Ton de chaque état métier. Un même ton signifie toujours la même chose :
 * vert = acquis, ambre = action attendue, rouge = refus ou anomalie,
 * bleu = en cours de traitement, gris = neutre ou clos.
 */
final class Tons
{
    private const TONS = [
        // Dossier
        'brouillon' => 'neutre',
        'recu' => 'info',
        'complet' => 'marine',
        'retour_rectification' => 'alerte',
        'rejete' => 'danger',
        'admis' => 'succes',
        'confirme' => 'succes',
        'en_attente' => 'alerte',
        'refuse' => 'danger',
        'desiste' => 'neutre',
        'delai_depasse' => 'neutre',
        'sejour_termine' => 'neutre',
        // Pièces, paiements
        'deposee' => 'info',
        'acceptee' => 'succes',
        'a_remplacer' => 'alerte',
        'autorise' => 'info',
        'recu_depose' => 'alerte',
        'valide' => 'succes',
        // Campagne
        'preparee' => 'neutre',
        'ouverte' => 'succes',
        'cloturee' => 'marine',
        // Référentiel
        'disponible' => 'succes',
        'occupe' => 'marine',
        'occupee' => 'marine',
        'hors_service' => 'neutre',
        'en_maintenance' => 'alerte',
        'ouvert' => 'succes',
        'en_travaux' => 'alerte',
        'ferme' => 'neutre',
        // Vie quotidienne
        'refusee' => 'danger',
        'signalee' => 'alerte',
        'en_cours' => 'info',
        'resolue' => 'succes',
        'traitee' => 'succes',
        // Restauration
        'propose' => 'alerte',
        'reserve' => 'info',
        'annule' => 'neutre',
        'servi' => 'succes',
        'absent' => 'danger',
        // Corrections, imports, exports
        'en_attente_cmc' => 'alerte',
        'approuve_cmc' => 'info',
        'approuve_regional' => 'succes',
        'analyse' => 'info',
        'importe' => 'succes',
        'termine' => 'succes',
        'echec' => 'danger',
        // Compte du stagiaire
        'actif' => 'succes',
        'suspendu' => 'danger',
        // Inscription en formation (vérification administrative)
        'non_declaree' => 'neutre',
        'declaree' => 'neutre',
        'soumise' => 'info',
        'remplacee' => 'neutre',
        'verifiee' => 'succes',
        'a_corriger' => 'alerte',
        'rejetee' => 'danger',
    ];

    public static function de(BackedEnum|string|null $etat): string
    {
        $valeur = $etat instanceof BackedEnum ? (string) $etat->value : (string) $etat;

        return self::TONS[$valeur] ?? 'neutre';
    }
}
