<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * D-53: the welcome email sent when an account is created, with the sign-in email and a temporary
 * password. Deliberately not queued: a queued notification would store the password in the jobs table.
 */
final class WelcomeEmployee extends Notification
{
    public function __construct(#[SensitiveParameter] public readonly string $password) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $app = (string) config('app.name');

        return (new MailMessage)
            ->subject("Welcome to {$app}")
            ->greeting("Welcome, {$notifiable->name}!")
            ->line("An account has been created for you on {$app}. You can sign in with these details:")
            ->line('**Email:** '.$notifiable->email)
            ->line('**Temporary password:** `'.$this->password.'`')
            ->action('Sign in', Filament::getLoginUrl())
            ->line('For your security, you will be asked to choose your own password the first time you sign in.')
            ->line('If you were not expecting this email, contact your administrator.');
    }
}
