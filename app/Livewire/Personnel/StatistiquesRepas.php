<?php

namespace App\Livewire\Personnel;

use App\Domain\Catering\StatistiquesRestauration;
use App\Livewire\PagePersonnel;
use App\Models\RegimeAlimentaire;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Statistiques mensuelles de restauration : repas réservés, confirmés, servis,
 * conformité des services, régimes particuliers des résidents. La gestion des
 * stocks et des coûts n'entre pas dans le périmètre de l'application.
 */
#[Title('Statistiques de restauration')]
class StatistiquesRepas extends PagePersonnel
{
    private const CONSULTER = 'restauration.consulter';

    /** Le détail nominatif des régimes et leurs justificatifs sont réservés à qui gère la restauration. */
    private const GERER = 'restauration.gerer';

    #[Url(except: '')]
    public string $mois = '';

    /** Régime dont le justificatif est ouvert. */
    #[Locked]
    public ?int $ligne = null;

    public function mount(): void
    {
        $this->exiger(self::CONSULTER);
        $this->mois = $this->periode();
    }

    private function periode(): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->mois) === 1 ? $this->mois : now()->format('Y-m');
    }

    public function voirJustificatif(StatistiquesRestauration $statistiques, int $id): void
    {
        $this->exiger(self::GERER);
        $regime = RegimeAlimentaire::findOrFail($id);
        abort_unless($statistiques->couvre($this->utilisateur(), $regime) && $regime->justificatif_fichier !== null, 404);

        $this->ligne = $regime->id;
        $this->ouvrirDialogue('justificatif');
    }

    public function fermerDialogue(): void
    {
        $this->ligne = null;
        $this->dialogue = null;
    }

    public function render()
    {
        $this->exiger(self::CONSULTER);
        $statistiques = app(StatistiquesRestauration::class);
        $utilisateur = $this->utilisateur();
        $mois = $this->periode();
        $nominatif = $this->peut(self::GERER);

        return view('livewire.personnel.statistiques-repas', $statistiques->duMois($utilisateur, $mois) + [
            'periode' => $mois,
            'nominatif' => $nominatif,
            'declares' => $nominatif ? $statistiques->regimes($utilisateur) : collect(),
            'ouvert' => $this->dialogue === 'justificatif' && $this->ligne !== null ? RegimeAlimentaire::with('stagiaire:id,nom,prenom')->find($this->ligne) : null,
        ]);
    }
}
