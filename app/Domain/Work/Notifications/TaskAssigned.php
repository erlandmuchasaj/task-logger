<?php

declare(strict_types=1);

namespace App\Domain\Work\Notifications;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Tasks\TaskResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** D-55: tells the new assignee about the task, with links to the task page and to log hours on it. */
final class TaskAssigned extends Notification
{
    public function __construct(public readonly Task $task, public readonly ?User $assignedBy = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $task = $this->task->loadMissing(['project.customer', 'technology', 'labels']);
        $by = $this->assignedBy?->getFilamentName();

        $mail = (new MailMessage)
            ->subject('New task assigned: '.$task->name)
            ->greeting("Hello {$notifiable->name},")
            ->line(($by !== null ? "{$by} assigned" : 'You have been assigned').' a new task'.($by !== null ? ' to you' : '').':')
            ->line('**'.$task->name.'**')
            ->line('**Project:** '.$task->project->name.($task->project->customer ? ' ('.$task->project->customer->name.')' : ''))
            ->line('**Technology:** '.($task->technology->name ?? '—'))
            ->line('**Priority:** '.$task->priority->getLabel().' · **Status:** '.$task->status->getLabel())
            ->line('**Estimated hours:** '.($task->estimated_hours ?? '—'))
            ->line('**Due date:** '.($task->due_date?->toFormattedDayDateString() ?? 'No due date'));

        if ($task->labels->isNotEmpty()) {
            $mail->line('**Labels:** '.$task->labels->pluck('name')->join(', '));
        }

        if ($task->force_to_log) {
            $mail->line('This task is pinned in your daily log until it is completed.');
        }

        if (filled($task->description)) {
            $mail->line('**Description:**')->line(str($task->description)->limit(1000)->toString());
        }

        return $mail
            ->action('Open the task', TaskResource::getUrl('view', ['record' => $task], panel: 'app'))
            ->line('To log your hours on it, use **Log hours** on the task page or go straight to: '.LogResource::getUrl('create', ['task' => $task->getKey()], panel: 'app'));
    }
}
