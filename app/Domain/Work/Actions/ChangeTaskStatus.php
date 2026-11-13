<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** BR-35: the assignee (own tasks), the creator (tasks made for others) and admins (any task). SPEC sets no transition rules. */
class ChangeTaskStatus
{
    public function handle(User $actor, Task $task, TaskStatus $status): Task
    {
        Gate::forUser($actor)->authorize('changeStatus', $task);

        return app(CauserResolver::class)->withCauser($actor, function () use ($task, $status): Task {
            $task->update(['status' => $status]);

            return $task->refresh();
        });
    }
}
