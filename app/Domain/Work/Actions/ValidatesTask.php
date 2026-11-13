<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Data\TaskData;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Support\TaskHierarchy;
use Illuminate\Validation\ValidationException;

trait ValidatesTask
{
    /** @throws ValidationException */
    private function validateTask(TaskData $data, Project $project, ?Task $existing): void
    {
        $errors = [];

        // BR-31: no new tasks for a deactivated client (existing tasks keep working).
        if ($existing === null && ! $project->customer->is_active) {
            $errors['project_id'] = 'This project\'s client is deactivated and cannot receive new tasks.';
        }

        // BR-10: the task's technology belongs to the task's project.
        if (! $project->technologyLines()->where('technology_id', $data->technologyId)->exists()) {
            $errors['technology_id'] = 'Choose one of the technologies of this project.';
        }

        if (! preg_match('/^\d{1,6}(\.\d{1,2})?$/', $data->estimatedHours)) {
            $errors['estimated_hours'] = 'Estimated hours must be a number with at most 2 decimals.';
        }

        // BR-11: parent in the same project, no cycle.
        if ($data->parentId !== null) {
            $parent = Task::query()->find($data->parentId);

            if ($parent === null || $parent->project_id !== $project->getKey()) {
                $errors['parent_id'] = 'The parent task must belong to the same project.';
            } elseif (TaskHierarchy::wouldCycle($existing?->getKey(), $data->parentId)) {
                $errors['parent_id'] = 'A task cannot be its own ancestor.';
            }
        }

        if ($data->assigneeId !== null) {
            $assignee = User::query()->find($data->assigneeId);

            if ($assignee === null || $assignee->isBanned() || $assignee->anonymized_at !== null) {
                $errors['assignee_id'] = 'Choose an active employee.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  list<int>  $labelIds */
    private function syncLabels(Task $task, array $labelIds): void
    {
        $changes = $task->labels()->sync($labelIds);

        if ($changes['attached'] !== [] || $changes['detached'] !== []) {
            activity()->performedOn($task)->event('labels_changed')
                ->withChanges(['attributes' => ['labels' => $labelIds]])->log('labels_changed');
        }
    }
}
