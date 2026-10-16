<?php

namespace App\Notifications;

use App\Models\NotificationInterne;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Courriel reprenant une notification déjà conservée dans la table `notification`.
 * Mis en file d'attente après validation de la transaction qui l'a produite.
 */
class MessageInternat extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $notification = NotificationInterne::findOrFail($this->notificationId);

        $courriel = (new MailMessage)
            ->subject(config('app.name').' — '.$notification->titre)
            ->greeting('Bonjour,')
            ->line($notification->message);

        if ($notification->lien) {
            $courriel->action('Ouvrir dans INTERNAT', rtrim((string) config('app.frontend_url'), '/').$notification->lien);
        }

        return $courriel->salutation('Message automatique, merci de ne pas y répondre.');
    }
}
