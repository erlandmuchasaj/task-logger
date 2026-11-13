<?php

declare(strict_types=1);

namespace App\Domain\Leave\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Filament\Pages\MyLeave;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** D-57: tells the employee their leave request was finally approved or rejected, by email and in the app. */
final class LeaveDecided extends Notification
{
    public function __construct(public readonly LeaveRequest $request, public readonly ?User $decidedBy = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function isApproved(): bool
    {
        return $this->request->status === LeaveStatus::Approved;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary().'.');

        if ($this->decidedBy instanceof User) {
            $mail->line('**Decided by:** '.$this->decidedBy->getFilamentName());
        }

        if (filled($this->request->review_note)) {
            $mail->line('**'.($this->isApproved() ? 'Note' : 'Reason').':** '.$this->request->review_note);
        }

        return $mail->action('Open my leave', MyLeave::getUrl(panel: 'app'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title())
            ->body($this->summary().(filled($this->request->review_note) ? '. '.$this->request->review_note : '.'))
            ->actions([
                Action::make('view')->label('View')->url(MyLeave::getUrl(panel: 'app'))->markAsRead(),
            ]);

        $this->isApproved() ? $notification->success() : $notification->danger();

        return $notification->getDatabaseMessage();
    }

    private function title(): string
    {
        return $this->isApproved() ? 'Your leave request was approved' : 'Your leave request was rejected';
    }

    private function summary(): string
    {
        $request = $this->request->loadMissing('leaveType');
        $start = $request->start_date->toFormattedDateString();
        $end = $request->end_date->toFormattedDateString();

        return ($request->leaveType?->label() ?? 'Leave').' from '.$start.($start === $end ? '' : ' to '.$end)
            .' ('.$request->days_requested.' days) was '.($this->isApproved() ? 'approved' : 'rejected');
    }
}
