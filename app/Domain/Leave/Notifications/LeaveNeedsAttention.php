<?php

declare(strict_types=1);

namespace App\Domain\Leave\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveAlert;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** D-70: tells an approver or HR that a leave request was made, is still pending soon before it starts, or starts today undecided. By email and in the app. */
final class LeaveNeedsAttention extends Notification
{
    public function __construct(public readonly LeaveRequest $request, public readonly LeaveAlert $alert) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->alert->getLabel())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary().'.')
            ->action('Open leave requests', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->alert->getLabel())
            ->body($this->summary().'.')
            ->actions([Action::make('view')->label('View')->url($this->url())->markAsRead()]);

        match ($this->alert) {
            LeaveAlert::Submitted => $notification->info(),
            LeaveAlert::Reminder => $notification->warning(),
            LeaveAlert::Escalated => $notification->danger(),
        };

        return $notification->getDatabaseMessage();
    }

    private function url(): string
    {
        return LeaveRequestResource::getUrl('index', ['tab' => 'table'], panel: 'app');
    }

    private function summary(): string
    {
        $request = $this->request->loadMissing(['leaveType', 'user']);
        $start = $request->start_date->toFormattedDateString();
        $end = $request->end_date->toFormattedDateString();

        return $request->user->getFilamentName().' asked for '.($request->leaveType?->label() ?? 'leave').' from '.$start
            .($start === $end ? '' : ' to '.$end).' ('.$request->days_requested.' days)';
    }
}
