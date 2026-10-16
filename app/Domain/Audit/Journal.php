<?php

namespace App\Domain\Audit;

use App\Models\JournalAudit;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Écriture du journal d'audit. Appelé par les services métier, dans la même
 * transaction que l'action tracée : une action annulée ne laisse pas de trace
 * fantôme, une action validée en laisse toujours une.
 */
class Journal
{
    /** Clés dont la valeur n'est jamais écrite dans le journal. */
    private const CLES_SENSIBLES = [
        'mot_de_passe', 'mot_de_passe_hash', 'password', 'mot_de_passe_myway', 'jeton', 'token',
        'remember_token', 'fichier', 'recu_fichier', 'rapport_fichier', 'justificatif_fichier',
    ];

    /**
     * @param  Model|string  $entite  Modèle concerné ou nom de table.
     * @param  array<string, mixed>  $details  Valeurs avant / après, ou motif.
     */
    public function consigner(string $action, Model|string $entite, ?int $entiteId = null, array $details = [], ?Model $acteur = null): JournalAudit
    {
        $acteur ??= Auth::guard('web')->user() ?? Auth::guard('stagiaire')->user();
        $requete = app()->runningInConsole() ? null : request();

        return JournalAudit::create([
            'utilisateur_id' => $acteur instanceof Utilisateur ? $acteur->id : null,
            'stagiaire_id' => $acteur instanceof Stagiaire ? $acteur->id : null,
            'action' => $action,
            'entite' => $entite instanceof Model ? $entite->getTable() : $entite,
            'entite_id' => $entite instanceof Model ? $entite->getKey() : $entiteId,
            'date_heure' => now(),
            'details' => $details === [] ? null : $this->expurger($details),
            'adresse_ip' => $requete?->ip(),
            'agent' => $requete ? mb_substr((string) $requete->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * Différences avant / après d'un modèle modifié, limitées aux champs changés.
     *
     * @return array{avant: array<string, mixed>, apres: array<string, mixed>}
     */
    public function differences(Model $modele): array
    {
        $apres = collect($modele->getChanges())->except(['updated_at'])->all();
        $avant = collect($apres)->map(fn ($v, $cle) => $modele->getRawOriginal($cle))->all();

        return ['avant' => $avant, 'apres' => $apres];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function expurger(array $details): array
    {
        foreach ($details as $cle => $valeur) {
            if (in_array($cle, self::CLES_SENSIBLES, true)) {
                $details[$cle] = '[masqué]';
            } elseif (is_array($valeur)) {
                $details[$cle] = $this->expurger($valeur);
            }
        }

        return $details;
    }
}
