<?php

namespace App\Http\Controllers;

use App\Domain\Admissions\CalculScore;
use App\Enums\StatutDemande;
use App\Models\Campagne;
use App\Models\Demande;
use Illuminate\Contracts\View\View;

/**
 * Liste imprimable des candidats d'une campagne, en vue de la délibération.
 *
 * Consultable par tout rôle qui a la consultation des admissions (chef de pôle,
 * directeur…), dans son périmètre : c'est une vue de lecture, elle ne délibère
 * rien. Elle réunit ce qu'il faut pour délibérer : identité, CEF, établissement,
 * filière, notes obtenues par critère, score total et statut.
 */
class ImpressionClassementController extends Controller
{
    public function imprimer(Campagne $campagne, CalculScore $calcul): View
    {
        $utilisateur = auth('web')->user();
        abort_unless($utilisateur?->can('admissions.consulter'), 403);
        // Périmètre : la campagne doit relever de l'utilisateur.
        abort_unless(Campagne::visiblesPar($utilisateur)->whereKey($campagne->id)->exists(), 404);

        $campagne->load('internat.etablissement');
        $deliberee = $campagne->estDeliberee();

        $statuts = $deliberee
            ? [StatutDemande::Admis, StatutDemande::Confirme, StatutDemande::EnAttente, StatutDemande::Refuse, StatutDemande::Desiste, StatutDemande::DelaiDepasse, StatutDemande::SejourTermine]
            : [StatutDemande::Complet];

        $dossiers = Demande::where('campagne_id', $campagne->id)
            ->whereIn('statut', array_map(fn (StatutDemande $s) => $s->value, $statuts))
            ->whereNotNull('rang')
            ->with('stagiaire:id,nom,prenom,cef,nom_filiere')
            ->orderBy('rang')->orderBy('date_soumission')->orderBy('id')
            ->get();

        $lignes = $dossiers->map(fn (Demande $demande) => [
            'demande' => $demande,
            'notes' => collect($calcul->evaluer($demande)['lignes'])
                ->filter(fn (array $l) => $l['points'] > 0)
                ->map(fn (array $l) => ['libelle' => $l['critere']->condition_texte, 'points' => $l['points']])
                ->values(),
        ]);

        return view('impression.classement', [
            'campagne' => $campagne,
            'lignes' => $lignes,
            'deliberee' => $deliberee,
            'imprimePar' => $utilisateur,
            'bareme' => (int) config('internat.score.bareme_maximum'),
        ]);
    }
}
