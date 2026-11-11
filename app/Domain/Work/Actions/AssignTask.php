<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Perm tasks.assign. One assignee per task (BR-09); null unassigns. */
class AssignTask
{
    public function handle(User $actor, Task $task, ?int $assigneeId): Task
    {
        Gate::forUser($actor)->authorize('assign', $task);

        if ($assigneeId !== null) {
            $assignee = User::query()->find($assigneeId);

            if ($assignee === null || $assignee->isBanned() || $assignee->anonymized_at !== null) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose an active employee.']);
            }
        }

        return app(CauserResolver::class)->withCauser($actor, function () use ($task, $assigneeId): Task {
            $task->update(['assignee_id' => $assigneeId]);

            return $task->refresh();
        });
    }
}
