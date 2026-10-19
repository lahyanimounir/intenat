<?php

namespace App\Domain\Catering;

use App\Domain\Audit\Journal;
use App\Domain\Notifications\Notifieur;
use App\Domain\Shared\RegleMetierException;
use App\Enums\RoleCode;
use App\Enums\StatutMenu;
use App\Enums\TypeNotification;
use App\Models\JournalAudit;
use App\Models\Menu;
use App\Models\Utilisateur;
use App\Support\Affichage\Format;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Circuit hebdomadaire des menus :
 *   1. le responsable restauration compose la semaine (petit-déjeuner, déjeuner, dîner) ;
 *   2. il la transmet au chef de pôle ;
 *   3. le chef de pôle la vérifie, puis la valide ou renvoie pour correction un repas,
 *      une journée ou toute la semaine, avec un commentaire obligatoire ;
 *   4. le responsable restauration corrige les seuls menus renvoyés et les retransmet.
 * Les menus validés ou non concernés par un renvoi gardent leur statut.
 * Seul un menu validé est diffusé aux stagiaires et se réserve. Chaque étape est
 * tracée au journal, qui fournit l'historique des validations.
 */
class ValidationDesMenus
{
    public const ACTIONS = [
        'menu.transmission_semaine', 'menu.validation_semaine', 'menu.validation',
        'menu.renvoi', 'menu.renvoi_journee', 'menu.renvoi_semaine',
    ];

    public function __construct(
        private readonly Restauration $restauration,
        private readonly Journal $journal,
        private readonly Notifieur $notifieur,
    ) {}

    /**
     * Menus de la semaine visibles du compte, éventuellement limités à un internat.
     *
     * @return Builder<Menu>
     */
    private function semaine(Utilisateur $compte, CarbonInterface $lundi, ?int $internatId): Builder
    {
        return Menu::visiblesPar($compte)
            ->whereBetween('date_repas', [$lundi->toDateString(), $lundi->copy()->addDays(6)->toDateString()])
            ->when($internatId !== null, fn (Builder $q) => $q->where('internat_id', $internatId));
    }

    /**
     * Transmet au chef de pôle les menus proposés de la semaine (jours à venir) qui ne l'ont pas encore été.
     *
     * @return int nombre de menus transmis
     */
    public function transmettreSemaine(CarbonInterface $lundi, ?int $internatId, Utilisateur $responsable): int
    {
        return DB::transaction(function () use ($lundi, $internatId, $responsable) {
            $menus = $this->semaine($responsable, $lundi, $internatId)
                ->where('statut', StatutMenu::Propose->value)->whereNull('transmis_le')
                ->whereDate('date_repas', '>=', now()->toDateString())
                ->lockForUpdate()->get();

            if ($menus->isEmpty()) {
                throw new RegleMetierException('Aucun menu proposé de cette semaine n\'est à transmettre.', 'rien_a_transmettre');
            }

            // Le commentaire d'un renvoi s'efface à la retransmission ; il reste dans l'historique.
            Menu::whereKey($menus->pluck('id')->all())->update([
                'transmis_le' => now(), 'transmis_par' => $responsable->id,
                'correction_motif' => null, 'correction_le' => null, 'correction_par' => null,
            ]);

            foreach ($menus->groupBy('internat_id') as $id => $duInternat) {
                $this->journal->consigner('menu.transmission_semaine', 'internat', (int) $id, [
                    'semaine' => $lundi->toDateString(), 'menus' => $duInternat->count(),
                ], $responsable);

                $this->notifieur->versRole(
                    RoleCode::ChefPole, (int) $id, TypeNotification::Approbation,
                    'Menus de la semaine à valider',
                    "Le responsable restauration vous a transmis {$duInternat->count()} menu(s) de la semaine du ".Format::dateLongue($lundi).' : vérifiez-les puis validez la semaine.',
                    $this->lien($lundi),
                );
            }

            return $menus->count();
        });
    }

    /**
     * Le chef de pôle valide d'un coup les menus transmis de la semaine : ils deviennent réservables.
     *
     * @return int nombre de menus validés
     */
    public function validerSemaine(CarbonInterface $lundi, ?int $internatId, Utilisateur $chef): int
    {
        return DB::transaction(function () use ($lundi, $internatId, $chef) {
            $menus = $this->aValider($chef, $lundi, $internatId)->lockForUpdate()->get();

            if ($menus->isEmpty()) {
                throw new RegleMetierException('Aucun menu transmis n\'attend votre validation pour cette semaine.', 'rien_a_valider');
            }

            foreach ($menus as $menu) {
                $this->restauration->validerMenu($menu, $chef, journaliser: false);
            }

            foreach ($menus->groupBy('internat_id') as $id => $duInternat) {
                $this->journal->consigner('menu.validation_semaine', 'internat', (int) $id, [
                    'semaine' => $lundi->toDateString(), 'menus' => $duInternat->count(),
                ], $chef);

                $this->notifieur->versRole(
                    RoleCode::Restauration, (int) $id, TypeNotification::Approbation,
                    'Menus de la semaine validés',
                    "Le chef de pôle a validé {$duInternat->count()} menu(s) de la semaine du ".Format::dateLongue($lundi).' : ils sont ouverts à la réservation.',
                    $this->lien($lundi),
                );
            }

            return $menus->count();
        });
    }

    /**
     * Renvoie un seul repas pour correction : les autres menus gardent leur statut.
     */
    public function renvoyerMenu(Menu $menu, string $motif, Utilisateur $chef): Menu
    {
        $motif = $this->motif($motif);

        return DB::transaction(function () use ($menu, $motif, $chef) {
            $menu = Menu::lockForUpdate()->findOrFail($menu->id);

            if (! $menu->enAttenteDeValidation()) {
                throw new RegleMetierException('Seul un menu transmis et en attente de validation se renvoie pour correction.', 'menu_non_renvoyable');
            }

            $this->marquerACorriger(collect([$menu]), $motif, $chef);
            $this->journal->consigner('menu.renvoi', $menu, details: [
                'date' => $menu->date_repas->toDateString(), 'repas' => $menu->type_repas->value, 'motif' => $motif,
            ], acteur: $chef);
            $this->prevenirLaRestauration($menu->internat_id, 'Le chef de pôle vous renvoie le '.mb_strtolower($menu->type_repas->libelle()).' du '.Format::dateLongue($menu->date_repas)." : {$motif}", $menu->date_repas);

            return $menu->fresh();
        });
    }

    /**
     * Renvoie pour correction les menus transmis d'une journée, dans un seul internat : quand
     * plusieurs internats sont concernés, il faut le préciser.
     *
     * @return int nombre de menus renvoyés
     */
    public function renvoyerJournee(CarbonInterface $jour, ?int $internatId, string $motif, Utilisateur $chef): int
    {
        $motif = $this->motif($motif);

        return DB::transaction(function () use ($jour, $internatId, $motif, $chef) {
            $menus = $this->semaine($chef, $jour->copy()->startOfDay(), $internatId)
                ->whereDate('date_repas', $jour->toDateString())
                ->where('statut', StatutMenu::Propose->value)->whereNotNull('transmis_le')
                ->lockForUpdate()->get();

            if ($menus->isEmpty()) {
                throw new RegleMetierException('Aucun menu transmis n\'est à renvoyer pour cette journée.', 'rien_a_renvoyer');
            }

            if ($menus->pluck('internat_id')->unique()->count() > 1) {
                throw new RegleMetierException('Plusieurs internats sont concernés ce jour-là : précisez l\'internat dont les menus sont à corriger.', 'internat_requis');
            }

            $id = (int) $menus->first()->internat_id;
            $this->marquerACorriger($menus, $motif, $chef);
            $this->journal->consigner('menu.renvoi_journee', 'internat', $id, [
                'jour' => $jour->toDateString(), 'menus' => $menus->count(), 'motif' => $motif,
            ], $chef);
            $this->prevenirLaRestauration($id, 'Le chef de pôle vous renvoie les menus du '.Format::dateLongue($jour)." : {$motif}", $jour);

            return $menus->count();
        });
    }

    /**
     * Renvoie pour correction les menus transmis de la semaine, dans le périmètre de l'internat choisi.
     *
     * @return int nombre de menus renvoyés
     */
    public function renvoyerSemaine(CarbonInterface $lundi, ?int $internatId, string $motif, Utilisateur $chef): int
    {
        $motif = $this->motif($motif);

        return DB::transaction(function () use ($lundi, $internatId, $motif, $chef) {
            $menus = $this->aValider($chef, $lundi, $internatId)->lockForUpdate()->get();

            if ($menus->isEmpty()) {
                throw new RegleMetierException('Aucun menu transmis n\'est à renvoyer pour cette semaine.', 'rien_a_renvoyer');
            }

            $this->marquerACorriger($menus, $motif, $chef);

            foreach ($menus->groupBy('internat_id') as $id => $duInternat) {
                $this->journal->consigner('menu.renvoi_semaine', 'internat', (int) $id, [
                    'semaine' => $lundi->toDateString(), 'menus' => $duInternat->count(), 'motif' => $motif,
                ], $chef);
                $this->prevenirLaRestauration((int) $id, 'Le chef de pôle vous renvoie les menus de la semaine du '.Format::dateLongue($lundi)." : {$motif}", $lundi);
            }

            return $menus->count();
        });
    }

    /** Commentaire obligatoire : il précise les modifications demandées. */
    private function motif(string $motif): string
    {
        $motif = trim($motif);

        if (mb_strlen($motif) < 5) {
            throw new RegleMetierException('Indiquez au responsable restauration les modifications demandées (5 caractères au moins).', 'commentaire_requis');
        }

        return mb_substr($motif, 0, 1000);
    }

    /** @param Collection<int, Menu> $menus */
    private function marquerACorriger(Collection $menus, string $motif, Utilisateur $chef): void
    {
        Menu::whereKey($menus->pluck('id')->all())->update([
            'transmis_le' => null, 'transmis_par' => null,
            'correction_motif' => $motif, 'correction_le' => now(), 'correction_par' => $chef->id,
        ]);
    }

    private function prevenirLaRestauration(int $internatId, string $message, CarbonInterface $jour): void
    {
        $this->notifieur->versRole(
            RoleCode::Restauration, $internatId, TypeNotification::Approbation,
            'Menus à corriger', $message, $this->lien($jour->copy()->startOfWeek(CarbonInterface::MONDAY)),
        );
    }

    /** @return Builder<Menu> menus transmis, en attente de validation */
    public function aValider(Utilisateur $compte, CarbonInterface $lundi, ?int $internatId): Builder
    {
        return $this->semaine($compte, $lundi, $internatId)
            ->where('statut', StatutMenu::Propose->value)->whereNotNull('transmis_le');
    }

    /**
     * Historique des transmissions, validations et renvois dans le périmètre du compte.
     *
     * @param  list<int>|null  $internatIds  internats du périmètre (null : tous)
     * @return Collection<int, JournalAudit>
     */
    public function historique(?array $internatIds, ?int $internatId = null, int $limite = 60): Collection
    {
        $traces = JournalAudit::with('utilisateur:id,nom,prenom')
            ->whereIn('action', self::ACTIONS)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $s->where('entite', 'internat')
                    ->when($internatIds !== null, fn (Builder $r) => $r->whereIn('entite_id', $internatIds))
                    ->when($internatId !== null, fn (Builder $r) => $r->where('entite_id', $internatId)))
                ->orWhere(fn (Builder $s) => $s->where('entite', 'menu')->whereIn('entite_id', Menu::query()->select('id')
                    ->when($internatIds !== null, fn (Builder $r) => $r->whereIn('internat_id', $internatIds))
                    ->when($internatId !== null, fn (Builder $r) => $r->where('internat_id', $internatId)))))
            ->orderByDesc('date_heure')->orderByDesc('id')->limit($limite)->get();

        return $traces;
    }

    private function lien(CarbonInterface $lundi): string
    {
        $decalage = (int) round(now()->startOfWeek(CarbonInterface::MONDAY)->startOfDay()->diffInWeeks($lundi->copy()->startOfDay(), false));

        return '/restauration/menus'.($decalage !== 0 ? '?semaine='.$decalage : '');
    }
}
