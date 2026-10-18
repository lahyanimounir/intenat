<?php

namespace App\Livewire\Personnel;

use App\Domain\Admissions\ListeAttente as AppelDeLaListeAttente;
use App\Enums\StatutDemande;
use App\Livewire\Concerns\AvecDetailScore;
use App\Livewire\PagePersonnel;
use App\Models\Campagne;
use App\Models\Demande;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Listes d'attente des campagnes délibérées. Quand une place se libère, le chef
 * de pôle est alerté ; la liste n'est jamais promue automatiquement : il choisit
 * un candidat, et motive son choix s'il n'appelle pas le mieux classé.
 */
#[Title('Liste d’attente')]
class ListeAttente extends PagePersonnel
{
    use AvecDetailScore;

    /** Campagne mise en avant à l'arrivée (lien d'une alerte « place libre »). */
    #[Url(except: '')]
    public string $campagne = '';

    public function mount(): void
    {
        $this->exiger('admissions.consulter', 'admissions.promouvoir');
    }

    public function promouvoir(AppelDeLaListeAttente $listeAttente, int $demande, ?string $motif = null): void
    {
        $ligne = Demande::findOrFail($demande);
        $this->autoriser($ligne, 'admissions.promouvoir');
        $motif = trim((string) $motif);

        $this->executer(
            fn () => $listeAttente->promouvoir($ligne, $this->utilisateur(), $motif === '' ? null : mb_substr($motif, 0, 1000)),
            'Candidat appelé : il dispose de 48 heures pour confirmer sa place.',
        );
    }

    public function fermerDialogue(): void
    {
        $this->scoreDe = null;
        $this->dialogue = null;
    }

    public function render()
    {
        $this->exiger('admissions.consulter', 'admissions.promouvoir');
        $utilisateur = $this->utilisateur();

        $listes = Campagne::visiblesPar($utilisateur)
            ->whereNotNull('date_deliberation')->whereNull('archivee_le')
            ->with('internat.etablissement:id,nom_efp')->orderByDesc('annee')->orderBy('internat_id')->orderByDesc('date_ouverture')->get()
            ->map(fn (Campagne $c) => (object) [
                'campagne' => $c,
                'placesLibres' => $c->placesLibres(),
                'candidats' => $c->demandes()->where('statut', StatutDemande::EnAttente->value)->whereNotNull('rang')
                    ->with('stagiaire:id,nom,prenom')->orderBy('rang')->orderBy('date_soumission')->orderBy('id')->limit(100)->get(),
            ]);

        return view('livewire.personnel.liste-attente', [
            'listes' => $listes,
            'peutAppeler' => $utilisateur->can('admissions.promouvoir'),
            'score' => $this->detailScore(),
        ]);
    }
}
