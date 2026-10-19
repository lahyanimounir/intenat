<?php

namespace App\Domain\Catering;

use App\Domain\Audit\Journal;
use App\Domain\Identity\Perimetre;
use App\Domain\Shared\RegleMetierException;
use App\Enums\StatutMenu;
use App\Enums\TypeRepas;
use App\Models\Menu;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Tenue de la carte : un menu est proposé, corrigé au besoin, transmis au chef
 * de pôle avec sa semaine, puis validé par celui-ci (voir ValidationDesMenus). Seul un menu validé est diffusé aux
 * stagiaires ; une fois validé, il ne se modifie ni ne se supprime plus.
 */
class CarteDesMenus
{
    public function __construct(
        private readonly Journal $journal,
        private readonly Perimetre $perimetre,
    ) {}

    /**
     * Propose le même repas dans un ou plusieurs internats du périmètre.
     *
     * @param  list<int>  $internatIds
     * @return list<Menu>
     */
    public function proposer(array $internatIds, CarbonInterface $jour, TypeRepas $repas, string $description, Utilisateur $auteur): array
    {
        if ($internatIds === []) {
            throw new RegleMetierException('Choisissez au moins un internat.', 'internat_requis');
        }

        if ($jour->copy()->startOfDay()->lessThan(now()->startOfDay())) {
            throw new RegleMetierException('Un menu ne se propose pas pour un jour passé.', 'jour_passe');
        }

        return DB::transaction(function () use ($internatIds, $jour, $repas, $description, $auteur) {
            $menus = [];

            foreach (array_unique($internatIds) as $internatId) {
                $this->perimetre->exigerInternat($auteur, (int) $internatId);

                $existe = Menu::where('internat_id', $internatId)->whereDate('date_repas', $jour)->where('type_repas', $repas->value)->exists();

                if ($existe) {
                    throw RegleMetierException::conflit('Un menu existe déjà pour ce repas, ce jour-là, dans cet internat.', 'menu_existant');
                }

                $menu = Menu::create([
                    'internat_id' => $internatId, 'date_repas' => $jour->toDateString(), 'type_repas' => $repas,
                    'description' => $description, 'statut' => StatutMenu::Propose, 'cree_par' => $auteur->id,
                ]);

                $this->journal->consigner('menu.creation', $menu, details: ['date' => $jour->toDateString(), 'repas' => $repas->value], acteur: $auteur);
                $menus[] = $menu;
            }

            return $menus;
        });
    }

    public function modifier(Menu $menu, string $description): Menu
    {
        if ($menu->statut === StatutMenu::Valide) {
            throw new RegleMetierException('Un menu validé est diffusé aux stagiaires : il ne se modifie plus.', 'menu_valide');
        }

        // Un menu modifié après sa transmission doit être retransmis : le chef de pôle valide ce qu'il a vérifié.
        $etaitTransmis = $menu->transmis_le !== null;
        $menu->fill(['description' => $description, 'transmis_le' => null, 'transmis_par' => null])->save();
        $this->journal->consigner('menu.modification', $menu, details: [
            'date' => $menu->date_repas->toDateString(), 'repas' => $menu->type_repas->value, 'transmission_annulee' => $etaitTransmis,
        ]);

        return $menu;
    }

    public function supprimer(Menu $menu): void
    {
        if ($menu->statut === StatutMenu::Valide || $menu->reservations()->exists()) {
            throw new RegleMetierException('Seul un menu proposé et sans réservation peut être supprimé.', 'suppression_interdite');
        }

        $this->journal->consigner('menu.suppression', $menu, details: ['date' => $menu->date_repas->toDateString()]);
        $menu->delete();
    }
}
