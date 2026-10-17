<?php

namespace App\Domain\Campaigns;

use App\Domain\Admissions\CalculScore;
use App\Domain\Audit\Journal;
use App\Domain\Identity\Perimetre;
use App\Domain\Shared\Parametres;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutBatiment;
use App\Enums\StatutCampagne;
use App\Enums\StatutLit;
use App\Enums\TypeChambre;
use App\Models\Campagne;
use App\Models\Critere;
use App\Models\CritereReference;
use App\Models\Internat;
use App\Models\Lit;
use App\Models\TypePiece;
use App\Models\Utilisateur;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'une campagne : Préparée → Ouverte → Clôturée, puis délibérée
 * et archivée. Les pièces exigées ne sont modifiables qu'en préparation. Les
 * critères de sélection sont figés (définis au niveau national par la Direction
 * centrale) et ne se corrigent pas en cours de campagne.
 */
class GestionCampagne
{
    public function __construct(
        private readonly Perimetre $perimetre,
        private readonly CalculScore $calcul,
        private readonly Parametres $parametres,
        private readonly Journal $journal,
    ) {}

    /**
     * Crée une campagne par internat choisi, préremplie avec la grille nationale de référence.
     *
     * @param  array{annee: string, date_ouverture: string, date_cloture: string, frais: float|string|null, internats: list<array{internat_id: int, nombre_places: int}>}  $donnees
     * @return list<Campagne>
     */
    public function creer(Utilisateur $chefDePole, array $donnees): array
    {
        return DB::transaction(function () use ($chefDePole, $donnees) {
            $campagnes = [];

            foreach ($donnees['internats'] as $ligne) {
                $this->perimetre->exigerInternat($chefDePole, (int) $ligne['internat_id']);
                $internat = Internat::findOrFail($ligne['internat_id']);

                if (Campagne::where('internat_id', $internat->id)->where('annee', $donnees['annee'])->exists()) {
                    throw RegleMetierException::conflit(
                        "Une campagne {$donnees['annee']} existe déjà pour « {$internat->nom} ».",
                        'campagne_existante',
                    );
                }

                $campagne = Campagne::create([
                    'internat_id' => $internat->id,
                    'annee' => $donnees['annee'],
                    'date_ouverture' => $donnees['date_ouverture'],
                    'date_cloture' => $donnees['date_cloture'],
                    'nombre_places' => $ligne['nombre_places'],
                    'frais' => $donnees['frais'] ?? null,
                    'statut' => StatutCampagne::Preparee,
                    'cree_par' => $chefDePole->id,
                ]);

                $this->appliquerGrilleDeReference($campagne);
                $this->journal->consigner('campagne.creation', $campagne, details: [
                    'annee' => $campagne->annee, 'nombre_places' => $campagne->nombre_places,
                ]);

                $campagnes[] = $campagne;
            }

            return $campagnes;
        });
    }

    /** @param array<string, mixed> $donnees */
    public function modifier(Campagne $campagne, array $donnees): Campagne
    {
        if ($campagne->estDeliberee()) {
            throw new RegleMetierException(
                'Les admissions sont validées : la campagne n\'est plus modifiable.',
                'campagne_deliberee',
            );
        }

        // Après l'ouverture, seules la date de clôture et le nombre de places restent ajustables.
        $modifiables = $campagne->statut === StatutCampagne::Preparee
            ? ['annee', 'date_ouverture', 'date_cloture', 'nombre_places', 'frais']
            : ['date_cloture', 'nombre_places'];

        $ignores = array_diff(array_keys($donnees), $modifiables);

        if ($ignores !== [] && $campagne->statut !== StatutCampagne::Preparee) {
            throw new RegleMetierException(
                'Après l\'ouverture, seuls la date de clôture et le nombre de places peuvent être ajustés.',
                'champ_verrouille',
            );
        }

        $campagne->fill(collect($donnees)->only($modifiables)->all());

        if ($campagne->date_cloture->lessThan($campagne->date_ouverture)) {
            throw new RegleMetierException('La date de clôture doit suivre la date d\'ouverture.', 'dates_incoherentes');
        }

        $campagne->save();
        $this->journal->consigner('campagne.modification', $campagne, details: $this->journal->differences($campagne));

        return $campagne;
    }

    /**
     * Contrôles préalables à l'ouverture.
     *
     * @return list<string> Points bloquants (vide si la campagne peut ouvrir).
     */
    public function controlesOuverture(Campagne $campagne): array
    {
        $campagne->loadMissing('criteres', 'typesPieces');
        $blocages = [];
        $bareme = (int) config('internat.score.bareme_maximum');
        $maximum = $this->calcul->maximumAtteignable($campagne);
        $lits = $this->litsEligibles($campagne->internat_id);

        if ($campagne->date_cloture->lessThan(now()->startOfDay())) {
            $blocages[] = 'La date de clôture est déjà passée.';
        }
        if ($campagne->nombre_places < 1) {
            $blocages[] = 'Le nombre de places offertes doit être d\'au moins 1.';
        }
        if ($campagne->frais === null) {
            $blocages[] = 'Les frais d\'internat ne sont pas renseignés.';
        }
        if ($campagne->typesPieces->isEmpty()) {
            $blocages[] = 'Aucune pièce justificative n\'est définie.';
        }
        if ($campagne->criteres->isEmpty()) {
            $blocages[] = 'La grille de critères est vide.';
        }
        if ($maximum > $bareme) {
            $blocages[] = "La grille permet d'atteindre {$maximum} points : le score est plafonné à {$bareme}.";
        }
        if ($campagne->nombre_places > $lits) {
            $blocages[] = "{$campagne->nombre_places} places offertes pour {$lits} lit(s) éligible(s) dans le référentiel de l'internat.";
        }

        return $blocages;
    }

    public function ouvrir(Campagne $campagne): Campagne
    {
        return DB::transaction(function () use ($campagne) {
            $campagne = Campagne::lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->statut !== StatutCampagne::Preparee) {
                throw new RegleMetierException('Seule une campagne en préparation peut être ouverte.', 'transition_interdite');
            }

            $blocages = $this->controlesOuverture($campagne);

            if ($blocages !== []) {
                throw new RegleMetierException(implode(' ', $blocages), 'ouverture_bloquee', ['blocages' => $blocages]);
            }

            $campagne->forceFill(['statut' => StatutCampagne::Ouverte])->save();
            $this->journal->consigner('campagne.ouverture', $campagne);

            return $campagne;
        });
    }

    public function cloturer(Campagne $campagne, bool $automatique = false): Campagne
    {
        return DB::transaction(function () use ($campagne, $automatique) {
            $campagne = Campagne::lockForUpdate()->findOrFail($campagne->id);

            if ($campagne->statut !== StatutCampagne::Ouverte) {
                throw new RegleMetierException('Seule une campagne ouverte peut être clôturée.', 'transition_interdite');
            }

            $campagne->forceFill(['statut' => StatutCampagne::Cloturee])->save();
            $this->journal->consigner('campagne.cloture', $campagne, details: ['automatique' => $automatique]);

            return $campagne;
        });
    }

    public function archiver(Campagne $campagne): Campagne
    {
        if (! $campagne->estDeliberee()) {
            throw new RegleMetierException('Seule une campagne dont les admissions sont validées peut être archivée.', 'transition_interdite');
        }

        if ($campagne->estArchivee()) {
            throw RegleMetierException::conflit('Cette campagne est déjà archivée.');
        }

        $campagne->forceFill(['archivee_le' => now()])->save();
        $this->journal->consigner('campagne.archivage', $campagne);

        return $campagne;
    }

    public function supprimer(Campagne $campagne): void
    {
        if ($campagne->statut !== StatutCampagne::Preparee || $campagne->demandes()->exists()) {
            throw new RegleMetierException('Seule une campagne en préparation et sans dossier peut être supprimée.', 'suppression_interdite');
        }

        $this->journal->consigner('campagne.suppression', $campagne, details: ['annee' => $campagne->annee]);
        $campagne->delete();
    }

    /** Tâche planifiée : clôture les campagnes dont la date de clôture est passée. */
    public function cloturerLesEchues(): int
    {
        $echues = Campagne::where('statut', StatutCampagne::Ouverte->value)
            ->whereDate('date_cloture', '<', now()->toDateString())->get();

        foreach ($echues as $campagne) {
            $this->cloturer($campagne, automatique: true);
        }

        return $echues->count();
    }

    public function exigerPreparation(Campagne $campagne): void
    {
        if ($campagne->statut !== StatutCampagne::Preparee) {
            throw new RegleMetierException(
                'La campagne est ouverte : pièces et critères ne se modifient plus librement. Un critère erroné se corrige par la procédure d\'approbation.',
                'campagne_verrouillee',
            );
        }
    }

    /** Lits pouvant être offerts : hors service exclus, et selon la règle, hors Isolement / Infirmerie. */
    public function litsEligibles(int $internatId): int
    {
        $exclure = $this->parametres->vrai('places.exclure_isolement_infirmerie');

        return Lit::query()
            ->where('statut', '!=', StatutLit::HorsService->value)
            ->whereHas('chambre', function ($chambre) use ($internatId, $exclure) {
                $chambre->when($exclure, fn ($q) => $q->whereNotIn('type_chambre', [
                    TypeChambre::Isolement->value, TypeChambre::Infirmerie->value,
                ]))->whereHas('batiment', fn ($b) => $b->where('internat_id', $internatId)
                    ->where('statut', StatutBatiment::Ouvert->value));
            })
            ->count();
    }

    /** Copie la grille nationale active dans la campagne ; elle reste adaptable. */
    public function appliquerGrilleDeReference(Campagne $campagne): int
    {
        $copies = 0;

        foreach (CritereReference::where('actif', true)->orderBy('id')->get() as $reference) {
            $typePiece = $reference->piece_libelle
                ? TypePiece::firstOrCreate(
                    ['campagne_id' => $campagne->id, 'libelle' => $reference->piece_libelle],
                    ['obligatoire' => false],
                )
                : null;

            Critere::firstOrCreate(
                [
                    'campagne_id' => $campagne->id,
                    'declencheur' => $reference->declencheur,
                    'valeur_attendue' => $reference->valeur_attendue,
                ],
                [
                    'type_piece_id' => $typePiece?->id,
                    'categorie' => $reference->categorie,
                    'condition_texte' => $reference->condition_texte,
                    'points' => $reference->points,
                ],
            );
            $copies++;
        }

        return $copies;
    }
}
