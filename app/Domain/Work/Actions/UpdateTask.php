<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\NotifyAssignee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Perm tasks.update. Reassigning needs tasks.assign and a status change follows BR-35. The project of
 * a task cannot change [ASSUMED]: parent, technology and later logs are tied to it.
 */
final readonly class UpdateTask
{
    use ValidatesTask;

    public function __construct(private ChangeTaskStatus $changeStatus) {}

    public function handle(User $actor, Task $task, TaskData $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        if ($data->projectId !== $task->project_id) {
            throw ValidationException::withMessages(['project_id' => 'The project of an existing task cannot be changed.']);
        }

        if ($data->assigneeId !== $task->assignee_id) {
            Gate::forUser($actor)->authorize('assign', $task);
        }

        $this->validateTask($data, $task->project()->with('customer')->firstOrFail(), $task);
        $previousAssigneeId = $task->assignee_id;

        $task = resolve(CauserResolver::class)->withCauser($actor, fn (): Task => DB::transaction(function () use ($actor, $task, $data): Task {
            $task->update([
                'name' => $data->name,
                'description' => $data->description,
                'technology_id' => $data->technologyId,
                'priority' => $data->priority,
                'estimated_hours' => $data->estimatedHours,
                'assignee_id' => $data->assigneeId,
                'parent_id' => $data->parentId,
                'force_to_log' => $data->forceToLog,
                'due_date' => $data->dueDate?->toDateString(),
            ]);

            $this->syncLabels($task, $data->labelIds);

            if ($data->status !== $task->status) {
                $this->changeStatus->handle($actor, $task, $data->status);
            }

            return $task->refresh();
        }));

        NotifyAssignee::ifChanged($actor, $task, $previousAssigneeId);

        return $task;
    }
}
