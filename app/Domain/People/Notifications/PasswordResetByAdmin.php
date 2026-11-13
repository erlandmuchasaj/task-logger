<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/** D-53: an administrator set a new password for the person. Not queued, so the password never reaches the jobs table. */
class PasswordResetByAdmin extends Notification
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
            ->subject("Your {$app} password has been reset")
            ->greeting("Hello {$notifiable->name},")
            ->line("An administrator has reset your {$app} password. You can sign in with these details:")
            ->line('**Email:** '.$notifiable->email)
            ->line('**New temporary password:** `'.$this->password.'`')
            ->action('Sign in', Filament::getLoginUrl())
            ->line('You will be asked to choose your own password when you sign in.')
            ->line('If you did not ask for this, contact your administrator.');
    }
}
