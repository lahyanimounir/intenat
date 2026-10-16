<?php

namespace App\Domain\Campaigns;

use App\Domain\Audit\Journal;
use App\Domain\Shared\RegleMetierException;
use App\Enums\DeclencheurCritere;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\TypePiece;

/**
 * Pièces exigées et grille de critères d'une campagne, et grille nationale de
 * référence. Pièces et critères ne se saisissent qu'en préparation (copiés
 * depuis la grille nationale figée) ; ils ne se corrigent pas une fois la
 * campagne ouverte.
 */
class GrilleCampagne
{
    public function __construct(
        private readonly GestionCampagne $campagnes,
        private readonly Journal $journal,
    ) {}

    /** @param  array{libelle: string, obligatoire: bool, formats_acceptes: string}  $donnees */
    public function enregistrerTypePiece(Campagne $campagne, ?TypePiece $type, array $donnees): TypePiece
    {
        $this->campagnes->exigerPreparation($campagne);
        $this->exigerAppartenance($campagne, $type?->campagne_id);

        $doublon = TypePiece::where('campagne_id', $campagne->id)->where('libelle', $donnees['libelle'])
            ->when($type, fn ($q) => $q->whereKeyNot($type->id))->exists();

        if ($doublon) {
            throw new RegleMetierException('Une pièce porte déjà ce libellé dans cette campagne.', 'piece_en_double');
        }

        $type ??= new TypePiece(['campagne_id' => $campagne->id]);
        $creation = ! $type->exists;
        $type->fill($donnees)->save();
        $this->journal->consigner($creation ? 'type_piece.creation' : 'type_piece.modification', $type, details: $donnees);

        return $type;
    }

    public function supprimerTypePiece(Campagne $campagne, TypePiece $type): void
    {
        $this->campagnes->exigerPreparation($campagne);
        $this->exigerAppartenance($campagne, $type->campagne_id);

        if ($type->criteres()->exists()) {
            throw new RegleMetierException('Cette pièce est rattachée à un critère : modifiez d\'abord le critère.', 'piece_utilisee');
        }

        $this->journal->consigner('type_piece.suppression', $type, details: ['libelle' => $type->libelle]);
        $type->delete();
    }

    /** @param  array<string, mixed>  $donnees  categorie, declencheur, valeur_attendue, condition_texte, points, type_piece_id */
    public function enregistrerCritere(Campagne $campagne, ?Critere $critere, array $donnees): Critere
    {
        $this->campagnes->exigerPreparation($campagne);
        $this->exigerAppartenance($campagne, $critere?->campagne_id);
        $donnees = $this->normaliser($donnees);

        if (($donnees['type_piece_id'] ?? null) !== null
            && ! TypePiece::where('campagne_id', $campagne->id)->whereKey($donnees['type_piece_id'])->exists()) {
            throw new RegleMetierException('Cette pièce n\'appartient pas à la campagne.', 'piece_hors_campagne');
        }

        // Une même donnée du dossier ne rapporte des points qu'une fois.
        $doublon = Critere::where('campagne_id', $campagne->id)
            ->where('declencheur', $donnees['declencheur'])->where('valeur_attendue', $donnees['valeur_attendue'])
            ->when($critere, fn ($q) => $q->whereKeyNot($critere->id))->exists();

        if ($doublon) {
            throw new RegleMetierException('Un critère existe déjà pour cette donnée du dossier.', 'critere_en_double');
        }

        $critere ??= new Critere(['campagne_id' => $campagne->id]);
        $creation = ! $critere->exists;
        $critere->fill($donnees)->save();
        $this->journal->consigner($creation ? 'critere.creation' : 'critere.modification', $critere, details: $donnees);

        return $critere;
    }

    public function supprimerCritere(Campagne $campagne, Critere $critere): void
    {
        $this->campagnes->exigerPreparation($campagne);
        $this->exigerAppartenance($campagne, $critere->campagne_id);

        $this->journal->consigner('critere.suppression', $critere, details: ['condition' => $critere->condition_texte, 'points' => $critere->points]);
        $critere->delete();
    }

    /** @param  array<string, mixed>  $donnees  categorie, declencheur, valeur_attendue, condition_texte, points, piece_libelle, actif */
    public function enregistrerCritereReference(?CritereReference $critere, array $donnees): CritereReference
    {
        $donnees = $this->normaliser($donnees);

        $critere ??= new CritereReference;
        $creation = ! $critere->exists;
        $critere->fill($donnees)->save();
        $this->journal->consigner($creation ? 'grille_reference.creation' : 'grille_reference.modification', $critere, details: $donnees);

        return $critere;
    }

    public function supprimerCritereReference(CritereReference $critere): void
    {
        $this->journal->consigner('grille_reference.suppression', $critere, details: ['condition' => $critere->condition_texte]);
        $critere->delete();
    }

    /**
     * Seul le critère « niveau de formation » porte une valeur attendue.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function normaliser(array $donnees): array
    {
        $donnees['valeur_attendue'] = $donnees['declencheur'] === DeclencheurCritere::NiveauFormation->value
            ? (string) ($donnees['valeur_attendue'] ?? '') : '';

        return $donnees;
    }

    private function exigerAppartenance(Campagne $campagne, ?int $campagneId): void
    {
        abort_if($campagneId !== null && $campagneId !== $campagne->id, 404);
    }
}
