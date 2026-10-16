<?php

namespace App\Domain\Admissions;

use App\Enums\DeclencheurCritere;
use App\Enums\StatutPiece;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\Demande;
use App\Models\DemandeCritere;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de notation.
 *
 * Le calcul est déterministe : pour un dossier et une version de grille donnés,
 * il produit toujours le même détail. Un critère rapporte ses points si, et
 * seulement si, la donnée du dossier qui le déclenche est vraie ET la pièce
 * justificative qui lui est rattachée a été acceptée par le gestionnaire.
 *
 * Aucun barème n'est codé ici : les points viennent de la table `critere`
 * de la campagne, saisie par l'établissement.
 */
class CalculScore
{
    /**
     * Détail du score sans rien écrire en base.
     *
     * @return array{total: int, lignes: list<array{critere: Critere, declare: bool, piece_requise: bool, piece_acceptee: bool, points: int}>}
     */
    public function evaluer(Demande $demande): array
    {
        $demande->loadMissing('campagne.criteres.typePiece', 'pieces', 'stagiaire');

        $acceptees = $demande->pieces
            ->filter(fn ($piece) => $piece->statut === StatutPiece::Acceptee)
            ->pluck('type_piece_id')
            ->flip();

        $lignes = [];
        $total = 0;

        foreach ($demande->campagne->criteres->sortBy('id') as $critere) {
            $declare = $this->estDeclenche($critere, $demande);
            $pieceRequise = $critere->type_piece_id !== null;
            $pieceAcceptee = ! $pieceRequise || $acceptees->has($critere->type_piece_id);
            $points = $declare && $pieceAcceptee ? $critere->points : 0;
            $total += $points;

            $lignes[] = [
                'critere' => $critere,
                'declare' => $declare,
                'piece_requise' => $pieceRequise,
                'piece_acceptee' => $pieceAcceptee,
                'points' => $points,
            ];
        }

        return ['total' => min($total, (int) config('internat.score.bareme_maximum')), 'lignes' => $lignes];
    }

    /** Calcule et enregistre le score, son détail et la version de grille utilisée. */
    public function calculer(Demande $demande): Demande
    {
        $resultat = $this->evaluer($demande);
        $version = $demande->campagne->version_grille;

        DB::transaction(function () use ($demande, $resultat, $version) {
            DemandeCritere::where('demande_id', $demande->id)->delete();

            $retenus = collect($resultat['lignes'])
                ->filter(fn (array $ligne) => $ligne['points'] > 0)
                ->map(fn (array $ligne) => [
                    'demande_id' => $demande->id,
                    'critere_id' => $ligne['critere']->id,
                    'points_obtenus' => $ligne['points'],
                    'version_grille' => $version,
                ])->values()->all();

            if ($retenus !== []) {
                DemandeCritere::insert($retenus);
            }

            $demande->forceFill([
                'score' => $resultat['total'],
                'version_grille' => $version,
                'date_calcul_score' => now(),
            ])->save();
        });

        return $demande;
    }

    /**
     * Plus haut score qu'un dossier peut atteindre avec la grille de la campagne :
     * somme des déclarations oui/non, plus le meilleur des niveaux de formation
     * (un stagiaire n'a qu'un niveau).
     */
    public function maximumAtteignable(Campagne $campagne): int
    {
        return $this->maximumDe($campagne->criteres);
    }

    /** @param Collection<int, Critere> $criteres */
    public function maximumDe(Collection $criteres): int
    {
        $parNiveau = $criteres->where('declencheur', DeclencheurCritere::NiveauFormation);
        $autres = $criteres->where('declencheur', '!==', DeclencheurCritere::NiveauFormation);

        return (int) $autres->sum('points') + (int) ($parNiveau->max('points') ?? 0);
    }

    private function estDeclenche(Critere $critere, Demande $demande): bool
    {
        return match ($critere->declencheur) {
            DeclencheurCritere::EloigneOuRural => (bool) $demande->eloigne_ou_rural,
            DeclencheurCritere::Demuni => (bool) $demande->demuni,
            DeclencheurCritere::OrphelinOuDivorce => (bool) $demande->orphelin_ou_divorce,
            DeclencheurCritere::BesoinsSpecifiques => (bool) $demande->besoins_specifiques,
            DeclencheurCritere::NiveauFormation => $demande->stagiaire->niveau_formation?->value === $critere->valeur_attendue,
        };
    }
}
