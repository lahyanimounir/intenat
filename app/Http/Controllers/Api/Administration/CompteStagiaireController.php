<?php

namespace App\Http\Controllers\Api\Administration;

use App\Domain\Audit\Journal;
use App\Domain\Identity\RecuperationCompte;
use App\Domain\Shared\RegleMetierException;
use App\Enums\EtatCompte;
use App\Enums\ResultatVerification;
use App\Http\Controllers\Controller;
use App\Models\Stagiaire;
use App\Models\VerificationIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comptes INTERNAT des stagiaires et vérifications d'identité non abouties.
 *
 * L'administrateur ne crée jamais de compte stagiaire et ne fixe jamais son mot
 * de passe : un compte naît d'une vérification d'identité réussie. Il peut
 * suspendre ou rétablir un compte, renvoyer le lien de réinitialisation, et
 * traiter ce qui a empêché une activation (établissement non référencé,
 * information manquante, inscriptions multiples, conflit d'identité).
 */
class CompteStagiaireController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $recherche = trim((string) $request->query('recherche', ''));

        $requete = Stagiaire::query()->with('etablissement:id,code_efp,nom_efp')
            ->when($request->filled('etat'), fn ($q) => $q->where('etat_compte', $request->string('etat')->toString()))
            ->when($recherche !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('cef', 'like', $recherche.'%')
                ->orWhere('email', 'like', '%'.$recherche.'%')
                ->orWhere('nom', 'like', $recherche.'%')
                ->orWhere('prenom', 'like', $recherche.'%')));

        $page = $this->paginer($this->trier($requete, $request, [
            'nom' => 'nom', 'cef' => 'cef', 'active_le' => 'compte_active_le', 'connexion' => 'derniere_connexion_le',
        ], 'nom'), $request);

        $page->getCollection()->transform(fn (Stagiaire $s) => $this->compte($s));

        return response()->json(['etats' => EtatCompte::options()] + $page->toArray());
    }

    public function suspendre(Request $request, Stagiaire $stagiaire, Journal $journal): JsonResponse
    {
        $donnees = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:255']]);

        if ($stagiaire->etat_compte !== EtatCompte::Actif) {
            throw new RegleMetierException('Seul un compte actif peut être suspendu.', 'etat_incompatible');
        }

        $stagiaire->forceFill([
            'etat_compte' => EtatCompte::Suspendu, 'suspendu_le' => now(), 'motif_suspension' => $donnees['motif'],
        ])->save();
        $journal->consigner('stagiaire.suspension', $stagiaire, details: ['motif' => $donnees['motif']]);

        return response()->json($this->compte($stagiaire->load('etablissement:id,code_efp,nom_efp')));
    }

    public function retablir(Stagiaire $stagiaire, Journal $journal): JsonResponse
    {
        if ($stagiaire->etat_compte !== EtatCompte::Suspendu) {
            throw new RegleMetierException('Ce compte n\'est pas suspendu.', 'etat_incompatible');
        }

        $stagiaire->forceFill(['etat_compte' => EtatCompte::Actif, 'suspendu_le' => null, 'motif_suspension' => null])->save();
        $journal->consigner('stagiaire.retablissement', $stagiaire);

        return response()->json($this->compte($stagiaire->load('etablissement:id,code_efp,nom_efp')));
    }

    /** Envoie au stagiaire le lien de réinitialisation : l'administrateur ne connaît ni ne choisit le mot de passe. */
    public function envoyerReinitialisation(Stagiaire $stagiaire, RecuperationCompte $recuperation, Journal $journal): JsonResponse
    {
        if (! $stagiaire->estActif()) {
            throw new RegleMetierException('Le lien de réinitialisation n\'est envoyé qu\'à un compte actif.', 'etat_incompatible');
        }

        $recuperation->demander('stagiaire', $stagiaire->email);
        $journal->consigner('stagiaire.reinitialisation_envoyee', $stagiaire);

        return response()->json(['message' => 'Le lien de réinitialisation a été envoyé à l\'adresse du compte.']);
    }

    /** Vérifications d'identité qui n'ont pas abouti à une activation. */
    public function verifications(Request $request): JsonResponse
    {
        $requete = VerificationIdentite::query()->with('examinateur:id,nom,prenom')
            ->where('resultat', '!=', ResultatVerification::Verifiee->value)
            ->when($request->filled('resultat'), fn ($q) => $q->where('resultat', $request->string('resultat')->toString()))
            ->when($request->query('etat') === 'a_examiner', fn ($q) => $q->whereNull('examinee_le'))
            ->when($request->query('etat') === 'examinees', fn ($q) => $q->whereNotNull('examinee_le'))
            ->orderByDesc('updated_at')->orderByDesc('id');

        $page = $this->paginer($requete, $request);

        $page->getCollection()->transform(fn (VerificationIdentite $v) => [
            'id' => $v->id,
            'resultat' => self::enum($v->resultat),
            'fournisseur' => $v->fournisseur,
            'simulee' => $v->simulee,
            'email' => $v->email,
            'cef' => $v->cef,
            'nom_efp' => $v->nom_efp,
            'champs_manquants' => $v->champs_manquants ?? [],
            'premiere_tentative_le' => $v->created_at,
            'derniere_tentative_le' => $v->updated_at,
            'examinee_le' => $v->examinee_le,
            'examinee_par' => $v->examinateur?->nomComplet(),
            'note_examen' => $v->note_examen,
        ]);

        return response()->json([
            'a_examiner' => VerificationIdentite::aExaminer()->count(),
            'resultats' => array_values(array_filter(
                ResultatVerification::options(),
                fn (array $option) => $option['valeur'] !== ResultatVerification::Verifiee->value,
            )),
        ] + $page->toArray());
    }

    /** Clôt l'examen d'une vérification : l'administrateur note ce qui a été fait (correspondance EFP ajoutée, stagiaire orienté…). */
    public function examiner(Request $request, VerificationIdentite $verification, Journal $journal): JsonResponse
    {
        $donnees = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']]);

        if ($verification->resultat === ResultatVerification::Verifiee || $verification->examinee_le !== null) {
            throw new RegleMetierException('Cette vérification n\'attend pas d\'examen.', 'etat_incompatible');
        }

        $verification->forceFill([
            'examinee_le' => now(), 'examinee_par' => $this->personnel()->id, 'note_examen' => $donnees['note'],
        ])->save();
        $journal->consigner('stagiaire.verification_examinee', $verification, details: [
            'resultat' => $verification->resultat->value, 'note' => $donnees['note'],
        ]);

        return response()->json(['message' => 'Examen enregistré.']);
    }

    /** @return array<string, mixed> */
    private function compte(Stagiaire $stagiaire): array
    {
        return [
            'id' => $stagiaire->id,
            'cef' => $stagiaire->cef,
            'nom_complet' => $stagiaire->nomComplet(),
            'email' => $stagiaire->email,
            'etablissement' => $stagiaire->etablissement?->only(['code_efp', 'nom_efp']),
            'etat_compte' => self::enum($stagiaire->etat_compte),
            'motif_suspension' => $stagiaire->motif_suspension,
            'suspendu_le' => $stagiaire->suspendu_le,
            'compte_active_le' => $stagiaire->compte_active_le,
            'derniere_connexion_le' => $stagiaire->derniere_connexion_le,
            'mot_de_passe_modifie_le' => $stagiaire->mot_de_passe_modifie_le,
            // `mock` : identité fictive du simulateur ; null : ligne de démonstration semée.
            'source_identite' => $stagiaire->source_identite,
            'statut_myway' => $stagiaire->statut_myway,
        ];
    }
}
