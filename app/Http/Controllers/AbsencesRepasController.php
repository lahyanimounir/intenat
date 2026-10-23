<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Journal;
use App\Domain\Catering\AbsencesRepas;
use App\Domain\Identity\Perimetre;
use App\Models\Menu;
use App\Support\Affichage\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impression PDF des absents aux repas : la liste d'un repas (« menu ») ou la
 * liste journalière de tous les repas d'un jour, regroupée par type de repas.
 */
class AbsencesRepasController extends Controller
{
    public function __invoke(Request $request, AbsencesRepas $absences, Perimetre $perimetre, Journal $journal): Response
    {
        $compte = $this->personnel();
        $menu = null;

        if ($request->filled('menu')) {
            $menu = Menu::findOrFail((int) $request->query('menu'));
            $this->autoriser($menu, 'restauration.consulter');
            $jour = $menu->date_repas->copy()->startOfDay();
            $internatId = $menu->internat_id;
        } else {
            $jour = rescue(fn () => Carbon::parse((string) $request->query('jour', now()->toDateString()))->startOfDay(), now()->startOfDay(), false);
            $internatId = $request->filled('internat') ? (int) $request->query('internat') : null;

            if ($internatId !== null) {
                $perimetre->exigerInternat($compte, $internatId);
            }
        }

        $internats = $absences->duJour($compte, $jour, $internatId, $menu?->id);

        $journal->consigner('restauration.absences_imprimees', 'menu', $menu?->id, [
            'jour' => $jour->toDateString(), 'internat' => $internatId, 'absences' => $internats->sum('total'),
        ], $compte);

        $titre = $menu !== null
            ? 'Liste des absents — '.$menu->type_repas->libelle()
            : 'Liste journalière des absences — Restauration';

        return Pdf::loadView('pdf.absences-repas', [
            'titre' => $titre,
            'jour' => Format::jour($jour),
            'internats' => $internats,
            'total' => $internats->sum('total'),
            'reference' => 'ABS-'.$jour->format('Ymd').($menu ? '-'.$menu->id : ''),
            'auteur' => $compte->nomComplet(),
            'etablissement' => $internats->pluck('etablissement')->unique()->implode(', ') ?: null,
        ])->setPaper('a4')->stream('absences-repas-'.$jour->toDateString().($menu ? '-'.$menu->type_repas->value : '').'.pdf');
    }
}
