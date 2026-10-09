<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientResetPassword extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('portal.password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Set your '.config('app.name').' password')
            ->line('Use the button below to set or reset the password for your client account.')
            ->action('Set password', $url)
            ->line('This link expires in '.config('auth.passwords.clients.expire').' minutes.')
            ->line('If you did not ask for this, you can ignore this email.');
    }
}
