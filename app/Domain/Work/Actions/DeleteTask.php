<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Perm tasks.delete. A soft delete; a task with sub-tasks is refused [DERIVED]. */
class DeleteTask
{
    public function handle(User $actor, Task $task): void
    {
        Gate::forUser($actor)->authorize('delete', $task);

        if ($task->children()->exists()) {
            throw ValidationException::withMessages(['task' => 'This task has sub-tasks. Move or delete them first.']);
        }

        app(CauserResolver::class)->withCauser($actor, fn () => $task->delete());
    }
}
