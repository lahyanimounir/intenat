<?php

namespace App\Domain\Notifications;

use App\Enums\RoleCode;
use App\Enums\TypeNotification;
use App\Models\NotificationInterne;
use App\Models\Stagiaire;
use App\Models\Utilisateur;
use App\Notifications\MessageInternat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique des notifications.
 *
 * Chaque message est d'abord conservé dans la table `notification` (traçabilité),
 * puis, si le destinataire a une adresse, un e-mail est mis en file d'attente.
 * La clé d'unicité garantit qu'une tâche planifiée rejouée ne notifie pas deux fois.
 */
class Notifieur
{
    public function versStagiaire(
        Stagiaire $stagiaire,
        TypeNotification $type,
        string $titre,
        string $message,
        ?string $lien = null,
        ?string $cle = null,
        bool $email = true,
    ): ?NotificationInterne {
        return $this->creer(['stagiaire_id' => $stagiaire->id], $stagiaire, $type, $titre, $message, $lien, $cle, $email);
    }

    public function versUtilisateur(
        Utilisateur $utilisateur,
        TypeNotification $type,
        string $titre,
        string $message,
        ?string $lien = null,
        ?string $cle = null,
        bool $email = true,
    ): ?NotificationInterne {
        if (! $utilisateur->actif) {
            return null;
        }

        return $this->creer(['utilisateur_id' => $utilisateur->id], $utilisateur, $type, $titre, $message, $lien, $cle, $email);
    }

    /**
     * Notifie le personnel d'un rôle donné ayant la charge de l'internat.
     *
     * @return int Nombre de notifications créées.
     */
    public function versRole(
        RoleCode $role,
        int $internatId,
        TypeNotification $type,
        string $titre,
        string $message,
        ?string $lien = null,
        ?string $cle = null,
        bool $email = true,
    ): int {
        $nombre = 0;

        foreach ($this->personnelDuRole($role, $internatId) as $utilisateur) {
            $cleUtilisateur = $cle !== null ? "{$cle}:u{$utilisateur->id}" : null;

            if ($this->versUtilisateur($utilisateur, $type, $titre, $message, $lien, $cleUtilisateur, $email)) {
                $nombre++;
            }
        }

        return $nombre;
    }

    /** @return Collection<int, Utilisateur> */
    public function personnelDuRole(RoleCode $role, int $internatId): Collection
    {
        $internat = DB::table('internat')
            ->join('etablissement', 'etablissement.id', '=', 'internat.etablissement_id')
            ->join('complexe', 'complexe.id', '=', 'etablissement.complexe_id')
            ->join('province', 'province.id', '=', 'complexe.province_id')
            ->where('internat.id', $internatId)
            ->first(['internat.id', 'internat.etablissement_id', 'province.region_id', 'etablissement.complexe_id']);

        if ($internat === null) {
            return collect();
        }

        return Utilisateur::query()
            ->role($role->value)
            ->where('actif', true)
            ->where(function ($q) use ($role, $internat) {
                match ($role) {
                    RoleCode::Gestionnaire => $q->where('internat_id', $internat->id),
                    RoleCode::ChefPole, RoleCode::Restauration => $q->where('etablissement_id', $internat->etablissement_id),
                    RoleCode::Directeur => $q->where('etablissement_id', $internat->etablissement_id)
                        ->when($internat->complexe_id, fn ($s) => $s->orWhere('complexe_id', $internat->complexe_id)),
                    RoleCode::DirRegionale => $q->where('region_id', $internat->region_id),
                    default => $q,
                };
            })
            ->get();
    }

    /** @param array<string, int> $destinataire */
    private function creer(
        array $destinataire,
        Stagiaire|Utilisateur $modele,
        TypeNotification $type,
        string $titre,
        string $message,
        ?string $lien,
        ?string $cle,
        bool $email,
    ): ?NotificationInterne {
        if ($cle !== null && NotificationInterne::where('cle_unicite', $cle)->exists()) {
            return null;
        }

        $avecEmail = $email && filled($modele->email);

        try {
            $notification = NotificationInterne::create($destinataire + [
                'type' => $type,
                'titre' => mb_substr($titre, 0, 160),
                'message' => $message,
                'lien' => $lien,
                'lue' => false,
                'date_envoi' => now(),
                'cle_unicite' => $cle,
                'email_statut' => $avecEmail ? 'en_file' : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Une autre exécution concurrente a déjà créé cette notification.
            return null;
        }

        if ($avecEmail) {
            $modele->notify(new MessageInternat($notification->id));
        }

        return $notification;
    }
}
