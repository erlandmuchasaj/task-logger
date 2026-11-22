<?php

declare(strict_types=1);

namespace App\Domain\Work\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** D-81: reminds the assignee that a task they are still working on is almost due. Carries no rate or amount. */
final class TaskDueSoon extends Notification
{
    public function __construct(public readonly Task $task) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $task = $this->task->loadMissing('project');

        return (new MailMessage)
            ->subject('Task due soon: '.$task->name)
            ->greeting("Hello {$notifiable->name},")
            ->line('A task assigned to you is almost due:')
            ->line('**'.$task->name.'**')
            ->line('**Project:** '.$task->project->name)
            ->line('**Due date:** '.($task->due_date?->toFormattedDayDateString() ?? '—'))
            ->line('**Status:** '.$task->status->getLabel())
            ->action('Open the task', TaskResource::getUrl('view', ['record' => $task], panel: 'app'))
            ->line('To log your hours on it, go straight to: '.LogResource::getUrl('create', ['task' => $task->getKey()], panel: 'app'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        $task = $this->task->loadMissing('project');

        return FilamentNotification::make()
            ->warning()
            ->title('Task due soon')
            ->body($task->name.' on '.$task->project->name.' is due on '.($task->due_date?->format('d/m/Y') ?? '—').'.')
            ->actions([
                Action::make('view')->label('View')->url(TaskResource::getUrl('view', ['record' => $task], panel: 'app'))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
