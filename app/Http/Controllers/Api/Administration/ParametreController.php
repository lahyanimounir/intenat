<?php

namespace App\Http\Controllers\Api\Administration;

use App\Domain\Audit\Journal;
use App\Domain\Shared\Parametres;
use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Models\Parametre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Règles de gestion paramétrables et journal d'audit (lecture seule).
 */
class ParametreController extends Controller
{
    /** Valeurs admises pour les paramètres à choix fermé. */
    private const CHOIX = [
        'classement.departage' => ['arbitrage_chef_pole', 'date_soumission'],
        'restauration.critere_blocage' => ['recu_non_depose', 'recu_non_valide', 'aucun'],
        'absence.alerte_destinataires' => ['gestionnaire', 'gestionnaire_et_chef_pole'],
    ];

    public function index(): JsonResponse
    {
        $enBase = Parametre::all()->keyBy('cle');

        return response()->json(collect(config('internat.parametres'))->map(function (array $defaut, string $cle) use ($enBase) {
            [$valeur, $libelle, $aValider] = $defaut;
            $ligne = $enBase->get($cle);

            return [
                'cle' => $cle,
                'libelle' => $libelle,
                'valeur' => $ligne ? $ligne->valeur : $valeur,
                'valeur_par_defaut' => $valeur,
                'type' => is_bool($valeur) ? 'booleen' : (is_int($valeur) ? 'entier' : 'texte'),
                'choix' => self::CHOIX[$cle] ?? null,
                'a_valider' => $ligne ? $ligne->a_valider : $aValider,
                'modifie_le' => $ligne?->updated_at,
            ];
        })->values());
    }

    public function update(Request $request, string $cle, Journal $journal, Parametres $parametres): JsonResponse
    {
        // Les clés contiennent des points : lecture directe du tableau, sans notation pointée.
        $defaut = config('internat.parametres')[$cle] ?? null;
        abort_if($defaut === null, 404);

        [$valeurDefaut, $libelle, $aValider] = $defaut;

        $regles = match (true) {
            is_bool($valeurDefaut) => ['required', 'boolean'],
            is_int($valeurDefaut) => ['required', 'integer', 'min:0', 'max:365'],
            isset(self::CHOIX[$cle]) => ['required', Rule::in(self::CHOIX[$cle])],
            default => ['required', 'string', 'max:100'],
        };

        $donnees = $request->validate(['valeur' => $regles, 'a_valider' => ['sometimes', 'boolean']]);
        $valeur = is_bool($valeurDefaut) ? (bool) $donnees['valeur'] : (is_int($valeurDefaut) ? (int) $donnees['valeur'] : $donnees['valeur']);

        if ($cle === 'restauration.heure_limite_reservation' && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $valeur)) {
            throw ValidationException::withMessages(['valeur' => 'Heure attendue au format HH:MM.']);
        }

        $ancienne = $parametres->lire($cle);

        $parametre = Parametre::updateOrCreate(['cle' => $cle], [
            'valeur' => $valeur,
            'libelle' => $libelle,
            'a_valider' => $donnees['a_valider'] ?? $aValider,
            'modifie_par' => $request->user()->id,
        ]);

        $parametres->oublier();
        $journal->consigner('parametre.modification', 'parametre', details: ['cle' => $cle, 'avant' => $ancienne, 'apres' => $valeur]);

        return response()->json($parametre);
    }

    public function audit(Request $request): JsonResponse
    {
        $requete = JournalAudit::query()->with('utilisateur:id,nom,prenom', 'stagiaire:id,nom,prenom')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->string('action')->toString().'%'))
            ->when($request->filled('entite'), fn ($q) => $q->where('entite', $request->string('entite')->toString()))
            ->when($request->filled('utilisateur_id'), fn ($q) => $q->where('utilisateur_id', $request->integer('utilisateur_id')))
            ->when($request->filled('du'), fn ($q) => $q->whereDate('date_heure', '>=', $request->date('du')))
            ->when($request->filled('au'), fn ($q) => $q->whereDate('date_heure', '<=', $request->date('au')))
            ->orderByDesc('id');

        $page = $this->paginer($requete, $request, 50);

        $page->getCollection()->transform(fn (JournalAudit $j) => [
            'id' => $j->id,
            'date_heure' => $j->date_heure,
            'action' => $j->action,
            'entite' => $j->entite,
            'entite_id' => $j->entite_id,
            'auteur' => $j->utilisateur?->nomComplet() ?? ($j->stagiaire ? $j->stagiaire->nomComplet().' (stagiaire)' : 'Système'),
            'details' => $j->details,
            'adresse_ip' => $j->adresse_ip,
        ]);

        return response()->json($page);
    }

    public function actionsAudit(): JsonResponse
    {
        return response()->json([
            'actions' => JournalAudit::query()->distinct()->orderBy('action')->pluck('action'),
            'entites' => JournalAudit::query()->distinct()->orderBy('entite')->pluck('entite'),
        ]);
    }
}
