<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Notifications\TaskDueSoon;
use App\Domain\Work\Support\WorkCalendar;

/**
 * D-81, the daily job that reminds an assignee of a task that is almost due. N days before the due date
 * (task-logger.task_due_reminder.days_before) the assignee gets one reminder, as long as the task is still being worked
 * on (TaskStatus::awaitsDueReminder). The day is the assignee's own (WorkCalendar::today). A run that was missed is made
 * up on the next one, up to the due date; an overdue task is not reminded about. The reminder is claimed with a
 * conditional update before sending, so a second run, or two at once, sends nothing twice. Changing the due date or the
 * assignee clears the claim (UpdateTask, AssignTask), so the new date or the new person gets a reminder.
 */
final class SendTaskDueReminders
{
    /** @return array{reminded: int} */
    public function handle(): array
    {
        $daysBefore = (int) config('task-logger.task_due_reminder.days_before');
        $counts = ['reminded' => 0];

        Task::query()
            ->whereNotNull('assignee_id')
            ->whereIn('status', TaskStatus::dueReminderStatuses())
            ->whereNull('due_reminder_sent_at')
            // One day of slack each way covers every timezone; the exact check below uses the assignee's own day.
            ->whereDate('due_date', '>=', now()->subDay()->toDateString())
            ->whereDate('due_date', '<=', now()->addDays($daysBefore + 1)->toDateString())
            ->with(['assignee.country', 'project'])
            ->each(function (Task $task) use ($daysBefore, &$counts): void {
                $assignee = $task->assignee;

                if (! $assignee instanceof User || $assignee->isBanned() || $assignee->anonymized_at !== null || $task->due_date === null) {
                    return;
                }

                $today = WorkCalendar::today($assignee)->toDateString();
                $due = $task->due_date->toDateString();

                if ($today > $due || $today < $task->due_date->subDays($daysBefore)->toDateString()) {
                    return;
                }

                if ($this->claim($task)) {
                    $assignee->notify(new TaskDueSoon($task));
                    $counts['reminded']++;
                }
            });

        return $counts;
    }

    /** True for exactly one caller: the one whose update changed the row from "not sent" to "sent". */
    private function claim(Task $task): bool
    {
        return Task::query()
            ->whereKey($task->getKey())
            ->whereIn('status', TaskStatus::dueReminderStatuses())
            ->where('assignee_id', $task->assignee_id)
            ->whereDate('due_date', $task->due_date?->toDateString())
            ->whereNull('due_reminder_sent_at')
            ->update(['due_reminder_sent_at' => now()]) === 1;
    }
}
