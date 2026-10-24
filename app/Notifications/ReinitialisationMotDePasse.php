<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Lien de réinitialisation du mot de passe INTERNAT, envoyé à l'adresse du compte.
 *
 * Mis en file d'attente pour que le temps de réponse ne révèle pas si l'adresse
 * correspond à un compte ; la charge de la tâche est chiffrée, le jeton n'étant
 * jamais lisible dans la table des files. Le jeton voyage dans le fragment de
 * l'adresse (après « # ») : le navigateur ne le transmet à aucun serveur et il
 * n'apparaît dans aucun journal d'accès.
 */
class ReinitialisationMotDePasse extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param  'personnel'|'stagiaire'  $espace */
    public function __construct(#[SensitiveParameter] public readonly string $jeton, public readonly string $espace) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function lien(): string
    {
        // Chaque espace a sa page : le lien mène à celle du compte concerné.
        $page = $this->espace === 'stagiaire' ? '/reinitialisation' : '/backend/reinitialisation';

        return rtrim((string) config('app.url'), '/').$page.'#'.http_build_query(['jeton' => $this->jeton]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $courtier = $this->espace === 'stagiaire' ? 'stagiaires' : 'utilisateurs';
        $minutes = (int) config("auth.passwords.{$courtier}.expire");

        return (new MailMessage)
            ->subject(config('app.name').' — Réinitialisation de votre mot de passe')
            ->greeting('Bonjour,')
            ->line('Une réinitialisation du mot de passe de votre compte Maisons des Stagiaires - OFPPT a été demandée.')
            ->action('Choisir un nouveau mot de passe', $this->lien())
            ->line("Ce lien est valable {$minutes} minutes et ne sert qu'une fois.")
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.')
            ->salutation('Message automatique, merci de ne pas y répondre.');
    }
}
