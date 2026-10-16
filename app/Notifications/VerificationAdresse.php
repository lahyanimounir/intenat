<?php

namespace App\Notifications;

use App\Models\Stagiaire;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Lien de confirmation de l'adresse e-mail d'un stagiaire qui vient de créer son
 * compte. Le lien est signé et limité dans le temps ; il prouve que l'adresse
 * appartient à la personne — rien de plus : ce n'est pas une preuve d'inscription
 * en formation.
 */
class VerificationAdresse extends Notification implements ShouldQueue
{
    use Queueable;

    public const MINUTES = 60;

    public int $tries = 3;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function lien(Stagiaire $stagiaire): string
    {
        return URL::temporarySignedRoute('stagiaire.adresse.confirmer', now()->addMinutes(self::MINUTES), [
            'stagiaire' => $stagiaire->getKey(),
            'empreinte' => sha1(mb_strtolower($stagiaire->email)),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(config('app.name').' — Confirmez votre adresse e-mail')
            ->greeting('Bonjour,')
            ->line('Un compte INTERNAT vient d\'être créé avec cette adresse. Confirmez qu\'elle vous appartient pour poursuivre votre inscription.')
            ->action('Confirmer mon adresse', self::lien($notifiable))
            ->line('Ce lien est valable '.self::MINUTES.' minutes.')
            ->line('Si vous n\'êtes pas à l\'origine de cette création de compte, ignorez ce message.')
            ->salutation('Message automatique, merci de ne pas y répondre.');
    }
}
