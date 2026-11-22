<?php

declare(strict_types=1);

namespace App\Domain\People\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Offboarding;
use App\Filament\Resources\Offboardings\OffboardingResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * D-75: tells a manager or HR that someone is leaving, by email and in the app. It names the person and the last day
 * and nothing else (the description is HR's note). The link shows only to people who may open Offboardings.
 */
final class OffboardingStarted extends Notification
{
    public function __construct(public readonly Offboarding $offboarding) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Offboarding started: '.$this->name())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary());

        return $this->canOpen($notifiable) ? $message->action('Open offboardings', $this->url()) : $message;
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title('Offboarding started: '.$this->name())
            ->body($this->summary())
            ->info();

        if ($this->canOpen($notifiable)) {
            $notification->actions([Action::make('view')->label('View')->url($this->url())->markAsRead()]);
        }

        return $notification->getDatabaseMessage();
    }

    private function canOpen(User $notifiable): bool
    {
        return $notifiable->can('viewAny', Offboarding::class);
    }

    private function url(): string
    {
        return OffboardingResource::getUrl('index', panel: 'app');
    }

    private function name(): string
    {
        return $this->offboarding->loadMissing('employee')->employee->getFilamentName();
    }

    private function summary(): string
    {
        return $this->name().' is leaving. Their last working day is '.$this->offboarding->end_date->toFormattedDateString().'.';
    }
}
