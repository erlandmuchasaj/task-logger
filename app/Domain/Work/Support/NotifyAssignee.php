<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Notifications\TaskAssigned;
use Illuminate\Support\Facades\DB;

/**
 * D-55: email the assignee when a task is given to them (on create, edit or reassignment). Not when the
 * assignee is unchanged, removed, or is the person making the change. Sent after the transaction
 * commits, so a rolled-back change never emails anyone.
 */
final class NotifyAssignee
{
    public static function ifChanged(User $actor, Task $task, ?int $previousAssigneeId): void
    {
        $assigneeId = $task->assignee_id;

        if ($assigneeId === null || $assigneeId === $previousAssigneeId || $assigneeId === $actor->getKey()) {
            return;
        }

        DB::afterCommit(function () use ($actor, $task, $assigneeId): void {
            $assignee = User::query()->find($assigneeId);

            if ($assignee !== null && ! $assignee->isBanned() && $assignee->anonymized_at === null) {
                $assignee->notify(new TaskAssigned($task, $actor));
            }
        });
    }
}
