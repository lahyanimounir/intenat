<?php

namespace App\Livewire\Personnel;

use App\Domain\Identity\ControleInscription;
use App\Domain\Reporting\TableauxDeBord;
use App\Livewire\PagePersonnel;
use App\Models\Stagiaire;
use App\Models\VerificationInscription;
use Livewire\Attributes\Title;

/**
 * Tableau de bord du personnel : un contenu par rôle, chacun répondant aux
 * tâches de ce rôle. Tous les chiffres sont des agrégats bornés au périmètre
 * du compte ; chaque compteur mène à la liste qu'il compte.
 */
#[Title('Tableau de bord')]
class TableauDeBord extends PagePersonnel
{
    /** Type de tableau => [titre, description]. */
    private const TITRES = [
        'administrateur' => ['Administration', 'Comptes, organisation, règles de gestion et cohérence des données.'],
        'gestionnaire' => ['Tableau de bord', 'Instruction des dossiers et vie de votre internat.'],
        'chef_pole' => ['Tableau de bord', 'Campagnes, admissions et capacité de votre établissement.'],
        'restauration' => ['Restauration', 'Menus, effectifs et service du jour.'],
        'directeur' => ['Tableau de bord du complexe', 'Indicateurs consolidés de vos établissements.'],
        'direction_regionale' => ['Indicateurs régionaux', 'Occupation et admissions des établissements de la région.'],
        'direction_centrale' => ['Pilotage national', 'Vue consolidée de l’ensemble des régions.'],
    ];

    public function render()
    {
        $utilisateur = $this->utilisateur();
        $donnees = app(TableauxDeBord::class)->pour($utilisateur);
        $type = $donnees['type'] ?? null;
        [$titre, $description] = self::TITRES[$type] ?? ['Tableau de bord', 'Bonjour '.$utilisateur->prenom.'.'];

        // L'en-tête nomme l'internat ou la région dont le compte a la charge.
        $lieu = match ($type) {
            'gestionnaire' => $donnees['internat']['nom'] ?? null,
            'direction_regionale' => $donnees['region']['nom'] ?? null,
            default => null,
        };

        return view('livewire.personnel.tableau-de-bord', [
            'd' => $donnees,
            'type' => $type,
            'titre' => $titre,
            'description' => $lieu ? $lieu.' — '.$description : $description,
            'inscriptionsAVerifier' => $this->inscriptionsAVerifier(),
        ]);
    }

    /** Inscriptions en formation soumises par les stagiaires du périmètre, en attente d'une décision. */
    private function inscriptionsAVerifier(): int
    {
        if (! $this->peut(ControleInscription::PERMISSION)) {
            return 0;
        }

        $stagiaires = $this->perimetre()->restreindreStagiaires(Stagiaire::query(), $this->utilisateur())->select('stagiaire.id');

        return VerificationInscription::query()->whereIn('stagiaire_id', $stagiaires)->enAttente()->count();
    }
}
