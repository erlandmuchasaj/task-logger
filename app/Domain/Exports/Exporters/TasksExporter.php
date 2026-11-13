<?php

declare(strict_types=1);

namespace App\Domain\Exports\Exporters;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class TasksExporter implements Exporter
{
    public function canExport(User $user): bool
    {
        return $user->can(Permissions::TASKS_EXPORT) && $user->can(Permissions::TASKS_VIEW_ANY);
    }

    public function columns(User $user): array
    {
        return [
            'name' => 'Task', 'project' => 'Project', 'client' => 'Client', 'technology' => 'Technology', 'assignee' => 'Assignee',
            'status' => 'Status', 'priority' => 'Priority', 'estimated_hours' => 'Estimated hours', 'due_date' => 'Due date',
            'labels' => 'Labels', 'pinned' => 'Pinned in daily log',
        ];
    }

    /** @return Builder<*> */
    public function query(User $user, array $filters): Builder
    {
        return Task::query()->with(['project.customer', 'technology', 'assignee', 'labels']);
    }

    public function row(Model $record, User $user): array
    {
        /** @var Task $task */
        $task = $record;

        return [
            'name' => $task->name,
            'project' => $task->project->name,
            'client' => $task->project->customer->displayName(),
            'technology' => $task->technology->name,
            'assignee' => $task->assignee?->getFilamentName(),
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'estimated_hours' => $task->estimated_hours,
            'due_date' => $task->due_date?->toDateString(),
            'labels' => $task->labels->pluck('name')->implode(', '),
            'pinned' => $task->force_to_log ? 'yes' : 'no',
        ];
    }
}
