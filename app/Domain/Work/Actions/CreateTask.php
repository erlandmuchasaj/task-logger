<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** W-03 step 1. Perm tasks.create; an assignee needs tasks.assign. One assignee only (BR-09). */
class CreateTask
{
    use ValidatesTask;

    public function handle(User $actor, TaskData $data): Task
    {
        Gate::forUser($actor)->authorize('create', Task::class);

        if ($data->assigneeId !== null) {
            Gate::forUser($actor)->authorize('assign', new Task);
        }

        $project = Project::query()->with('customer')->findOrFail($data->projectId);
        $this->validateTask($data, $project, null);

        return app(CauserResolver::class)->withCauser($actor, fn (): Task => DB::transaction(function () use ($actor, $data): Task {
            $task = Task::create([
                'created_by' => $actor->getKey(),
                'name' => $data->name,
                'description' => $data->description,
                'project_id' => $data->projectId,
                'technology_id' => $data->technologyId,
                'status' => $data->status,
                'priority' => $data->priority,
                'estimated_hours' => $data->estimatedHours,
                'assignee_id' => $data->assigneeId,
                'parent_id' => $data->parentId,
                'force_to_log' => $data->forceToLog,
                'due_date' => $data->dueDate?->toDateString(),
            ]);

            $this->syncLabels($task, $data->labelIds);

            return $task->refresh();
        }));
    }
}
