<?php

declare(strict_types=1);

namespace App\Domain\Work\Support;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Task;
use Illuminate\Database\Eloquent\Collection;

/** What a person can log time on (BR-03, BR-08). */
final class LogChoices
{
    /**
     * BR-03 unchecked: the user's own tasks. Open tasks only; the task of the log being edited is always included.
     *
     * @return array<int, string> task id => "Project - Task"
     */
    public static function taskOptions(User $owner, ?int $includeTaskId = null): array
    {
        return Task::query()->with('project')
            ->where('assignee_id', $owner->getKey())
            ->where(fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])->when($includeTaskId, fn ($q) => $q->orWhere('id', $includeTaskId)))
            ->orderByDesc('force_to_log')->orderBy('id', 'desc')
            ->get()
            ->mapWithKeys(fn (Task $t): array => [$t->id => ($t->force_to_log ? '📌 ' : '').$t->project->name.' - '.$t->name])
            ->all();
    }

    /**
     * BR-08: tasks with force_to_log, pinned in the assignee's daily log every day until completed or cancelled.
     *
     * @return Collection<int, Task>
     */
    public static function pinnedTasks(User $owner): Collection
    {
        return Task::query()->with(['project', 'technology'])
            ->where('assignee_id', $owner->getKey())
            ->where('force_to_log', true)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->orderBy('id')
            ->get();
    }

    /**
     * BR-03 checked: only projects that allow direct logging and where the user is a member (D-12).
     *
     * @return array<int, string> project id => name
     */
    public static function directProjectOptions(User $owner, ?int $includeProjectId = null): array
    {
        return Project::query()->with('customer')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('can_log_directly_to_project', true)
                ->whereHas('memberships', fn ($m) => $m->where('user_id', $owner->getKey()))
                ->whereHas('customer', fn ($c) => $c->where('is_active', true)))
                ->when($includeProjectId, fn ($q) => $q->orWhere('id', $includeProjectId)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int, string> technology id => name */
    public static function projectTechnologyOptions(?int $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        return Project::query()->find($projectId)?->technologyLines()->with('technology')->get()
            ->mapWithKeys(fn ($line): array => [$line->technology_id => $line->technology->name])->all() ?? [];
    }
}
