<?php

namespace App\Livewire\Stagiaire;

use App\Domain\Catering\Restauration;
use App\Domain\Shared\Documents;
use App\Enums\StatutMenu;
use App\Enums\StatutReservation;
use App\Enums\TypeRegime;
use App\Livewire\PageStagiaire;
use App\Models\Menu;
use App\Models\RegimeAlimentaire;
use App\Models\ReservationRepas;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Repas du résident : menus validés des quatorze prochains jours, réservation
 * ou annulation jusqu'à l'heure limite, et déclaration de ses régimes
 * alimentaires. Les règles — résidence, heure limite, blocage du mois non
 * réglé — sont celles du service Restauration.
 */
#[Title('Repas')]
class Repas extends PageStagiaire
{
    use WithFileUploads;

    public string $type = '';

    public string $precision = '';

    /** @var TemporaryUploadedFile|null */
    public $justificatif = null;

    public function reserver(Restauration $restauration, int $menu): void
    {
        $this->executer(fn () => $restauration->reserver($this->stagiaire(), Menu::findOrFail($menu)), 'Repas réservé.');
    }

    public function annuler(Restauration $restauration, int $reservation): void
    {
        $ligne = ReservationRepas::where('stagiaire_id', $this->stagiaire()->id)->findOrFail($reservation);

        $this->executer(fn () => $restauration->annuler($ligne), 'Réservation annulée.');
    }

    public function declarerRegime(Documents $documents): void
    {
        $stagiaire = $this->stagiaire();

        $valides = $this->validate([
            'type' => ['required', Rule::enum(TypeRegime::class), Rule::unique('regime_alimentaire', 'type')->where('stagiaire_id', $stagiaire->id)],
            'precision' => ['nullable', 'string', 'max:255'],
            'justificatif' => ['required_unless:type,'.TypeRegime::RestrictionReligieuse->value, 'nullable', 'file'],
        ], [
            'type.unique' => 'Ce régime est déjà déclaré.',
            'justificatif.required_unless' => 'Un justificatif est requis pour une allergie ou une restriction médicale.',
        ], ['type' => 'type de régime', 'precision' => 'précision', 'justificatif' => 'justificatif']);

        $reussi = $this->executer(function () use ($documents, $stagiaire, $valides) {
            $chemin = $this->justificatif !== null
                ? $documents->enregistrer($this->justificatif, "regimes/{$stagiaire->id}", config('internat.documents.extensions'), (int) config('internat.documents.taille_max_ko'))['chemin']
                : null;

            RegimeAlimentaire::create([
                'stagiaire_id' => $stagiaire->id,
                'type' => $valides['type'],
                'precision_regime' => $valides['precision'] ?: null,
                'justificatif_fichier' => $chemin,
            ]);
        }, 'Régime alimentaire déclaré.');

        if ($reussi) {
            $this->reset('type', 'precision', 'justificatif');
            $this->fermerDialogue();
        }
    }

    public function retirerRegime(Documents $documents, int $regime): void
    {
        $ligne = RegimeAlimentaire::where('stagiaire_id', $this->stagiaire()->id)->findOrFail($regime);

        $documents->supprimer($ligne->justificatif_fichier);
        $ligne->delete();
        $this->message('Régime retiré.');
    }

    public function render()
    {
        $restauration = app(Restauration::class);
        $stagiaire = $this->stagiaire();
        $affectation = $stagiaire->affectationActive();
        $jours = collect();

        if ($affectation !== null) {
            $reservations = ReservationRepas::where('stagiaire_id', $stagiaire->id)->get()->keyBy('menu_id');

            $jours = Menu::where('internat_id', $affectation->internatId())
                ->where('statut', StatutMenu::Valide->value)
                ->whereBetween('date_repas', [now()->toDateString(), now()->addDays(14)->toDateString()])
                ->with('service')
                ->orderBy('date_repas')->orderByRaw("FIELD(type_repas, 'petit_dejeuner', 'dejeuner', 'diner')")
                ->get()
                ->map(function (Menu $menu) use ($reservations, $restauration, $stagiaire) {
                    $reservation = $reservations->get($menu->id);
                    $reserve = $reservation?->statut === StatutReservation::Reserve;
                    $limite = $restauration->limiteDeReservation($menu);

                    return (object) [
                        'menu' => $menu,
                        'reservation' => $reservation,
                        'reserve' => $reserve,
                        'limite' => $limite,
                        'modifiable' => $menu->service?->effectif_confirme === null && now()->lessThanOrEqualTo($limite),
                        'blocage' => $reserve ? null : $restauration->motifIneligibilite($stagiaire, $menu),
                    ];
                })
                ->groupBy(fn (object $ligne) => $ligne->menu->date_repas->toDateString());
        }

        return view('livewire.stagiaire.repas', [
            'resident' => $affectation !== null,
            'jours' => $jours,
            'regimes' => RegimeAlimentaire::where('stagiaire_id', $stagiaire->id)->get(),
        ]);
    }
}
