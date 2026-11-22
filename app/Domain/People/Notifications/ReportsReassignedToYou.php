<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * D-74: tells the new manager, by email and in the app, who now reports to them. It does not say why the team moved.
 */
final class ReportsReassignedToYou extends Notification
{
    /** @param list<string> $names the people who moved, by display name */
    public function __construct(public readonly array $names) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New direct reports')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary());
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title('New direct reports')
            ->body($this->summary())
            ->info()
            ->getDatabaseMessage();
    }

    private function summary(): string
    {
        $count = count($this->names);

        return ($count === 1 ? '1 person now reports' : $count.' people now report').' to you: '.implode(', ', $this->names).'.';
    }
}
