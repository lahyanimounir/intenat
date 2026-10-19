<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Journal;
use App\Domain\Catering\ListeDesRepas;
use App\Domain\Identity\Perimetre;
use App\Enums\TypeRepas;
use App\Models\Internat;
use App\Support\Affichage\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impression PDF de la liste nominative J+1, avec les mêmes filtres que l'écran
 * et le total des repas par type.
 */
class ListeDesRepasController extends Controller
{
    public function __invoke(Request $request, ListeDesRepas $liste, Perimetre $perimetre, Journal $journal): Response
    {
        $compte = $this->personnel();
        $jour = rescue(fn () => Carbon::parse((string) $request->query('jour', now()->addDay()->toDateString()))->startOfDay(), now()->addDay()->startOfDay(), false);
        $internatId = $request->filled('internat') ? (int) $request->query('internat') : null;
        $repas = TypeRepas::tryFrom((string) $request->query('repas'));
        $attention = $request->boolean('attention');

        if ($internatId !== null) {
            $perimetre->exigerInternat($compte, $internatId);
        }

        $toutes = $liste->lignes($compte, $jour, $internatId);
        $lignes = $toutes
            ->when($repas !== null, fn ($c) => $c->filter(fn (object $l) => $l->repas === $repas))
            ->when($attention, fn ($c) => $c->where('attention', true))
            ->values();
        $totaux = $liste->totaux($toutes);

        $journal->consigner('restauration.liste_j1_imprimee', 'menu', null, [
            'jour' => $jour->toDateString(), 'internat' => $internatId, 'repas' => $repas?->value, 'lignes' => $lignes->count(),
        ], $compte);

        $criteres = array_filter([
            $internatId !== null ? Internat::whereKey($internatId)->value('nom') : null,
            $repas?->libelle(),
            $attention ? 'régime ou allergie uniquement' : null,
        ]);

        return Pdf::loadView('pdf.liste-repas', [
            'titre' => 'Liste nominative des repas — '.Format::jour($jour),
            'sousTitre' => ($criteres !== [] ? implode(' · ', $criteres).' — ' : '').$lignes->count().' repas réservé(s)',
            'lignes' => $lignes,
            'totaux' => $totaux,
            'parRepas' => $lignes->groupBy(fn (object $l) => $l->repas->value),
            'reference' => 'J1-'.$jour->format('Ymd'),
            'auteur' => $compte->nomComplet(),
            'etablissement' => null,
        ])->setPaper('a4', 'landscape')->stream('liste-repas-'.$jour->toDateString().'.pdf');
    }
}
