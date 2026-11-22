<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * D-74: tells a person, by email and in the app, that they have a new manager. It names the new and the previous
 * manager and does not say why.
 */
final class ManagerChanged extends Notification
{
    public function __construct(public readonly string $newManager, public readonly string $previousManager) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your manager has changed')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary());
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Your manager has changed')
            ->body($this->summary())
            ->info()
            ->getDatabaseMessage();
    }

    private function summary(): string
    {
        return 'Your manager is now '.$this->newManager.'. Before, it was '.$this->previousManager.'.';
    }
}
