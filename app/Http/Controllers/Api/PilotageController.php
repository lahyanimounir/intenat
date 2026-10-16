<?php

namespace App\Http\Controllers\Api;

use App\Domain\Reporting\Exports;
use App\Domain\Reporting\TableauxDeBord;
use App\Domain\Shared\Documents;
use App\Enums\StatutExport;
use App\Http\Controllers\Controller;
use App\Models\Export;
use App\Models\NotificationInterne;
use App\Models\Stagiaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tableaux de bord par rôle, exports asynchrones et notifications.
 */
class PilotageController extends Controller
{
    public function tableauDeBord(TableauxDeBord $tableaux): JsonResponse
    {
        return response()->json($tableaux->pour($this->personnel()));
    }

    // ---------------------------------------------------------------- Exports

    public function exports(Request $request, Exports $exports): JsonResponse
    {
        $utilisateur = $this->personnel();

        $page = $this->paginer(Export::where('utilisateur_id', $utilisateur->id)->latest('id'), $request, 15);
        $page->getCollection()->transform(fn (Export $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'libelle' => Exports::TYPES[$e->type]['libelle'] ?? $e->type,
            'format' => $e->format,
            'statut' => self::enum($e->statut),
            'lignes' => $e->lignes,
            'erreur' => $e->erreur,
            'telechargeable' => $e->statut === StatutExport::Termine && $e->fichier !== null,
            'cree_le' => $e->created_at,
            'expire_le' => $e->expire_le,
        ]);

        return response()->json(['types' => $exports->typesPour($utilisateur), 'exports' => $page]);
    }

    public function demanderExport(Request $request, Exports $exports): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(Exports::TYPES))],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'filtres' => ['sometimes', 'array'],
            'filtres.campagne_id' => ['nullable', 'integer'],
            'filtres.internat_id' => ['nullable', 'integer'],
            'filtres.statut' => ['nullable', 'string', 'max:40'],
            'filtres.periode' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'filtres.mois' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $export = $exports->demander($this->personnel(), $donnees['type'], $donnees['format'], array_filter($donnees['filtres'] ?? []));

        return response()->json(['id' => $export->id, 'statut' => self::enum($export->fresh()->statut)], 202);
    }

    /** Un export n'est téléchargeable que par celui qui l'a demandé. */
    public function telechargerExport(Export $export, Documents $documents): Response
    {
        abort_if($export->utilisateur_id !== $this->personnel()->id, 404);
        abort_if($export->statut !== StatutExport::Termine || $export->fichier === null, 404);

        return $documents->servir(
            $export->fichier,
            $export->nom_fichier ?? "export-{$export->id}.{$export->format}",
            $export->format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
            false,
        );
    }

    // ---------------------------------------------------------- Notifications

    public function notifications(Request $request): JsonResponse
    {
        $compte = $request->user();
        $colonne = $compte instanceof Stagiaire ? 'stagiaire_id' : 'utilisateur_id';

        $requete = NotificationInterne::where($colonne, $compte->getAuthIdentifier())
            ->when($request->query('non_lues') === '1', fn ($q) => $q->where('lue', false))
            ->orderByDesc('date_envoi')->orderByDesc('id');

        $page = $this->paginer($requete, $request, 20);
        $page->getCollection()->transform(fn (NotificationInterne $n) => [
            'id' => $n->id, 'type' => self::enum($n->type), 'titre' => $n->titre, 'message' => $n->message,
            'lien' => $n->lien, 'lue' => $n->lue, 'date_envoi' => $n->date_envoi,
        ]);

        return response()->json([
            'notifications' => $page,
            'non_lues' => NotificationInterne::where($colonne, $compte->getAuthIdentifier())->where('lue', false)->count(),
        ]);
    }

    public function lireNotification(Request $request, ?NotificationInterne $notification = null): JsonResponse
    {
        $compte = $request->user();
        $colonne = $compte instanceof Stagiaire ? 'stagiaire_id' : 'utilisateur_id';

        if ($notification !== null) {
            abort_if($notification->{$colonne} !== $compte->getAuthIdentifier(), 404);
        }

        NotificationInterne::where($colonne, $compte->getAuthIdentifier())->where('lue', false)
            ->when($notification, fn ($q) => $q->whereKey($notification->id))
            ->update(['lue' => true, 'date_lecture' => now()]);

        return response()->json([
            'non_lues' => NotificationInterne::where($colonne, $compte->getAuthIdentifier())->where('lue', false)->count(),
        ]);
    }
}
