<?php

declare(strict_types=1);

namespace App\Domain\Work\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Models\Task;

/** Permission-first (SPEC 4.1); relationship rules (assignee, creator) sit on top. */
class TaskPolicy
{
    /** Open the task list: everyone sees at least their own tasks. */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::TASKS_VIEW_ANY) || $user->can(Permissions::TASKS_VIEW_OWN);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can(Permissions::TASKS_VIEW_ANY)
            || ($user->can(Permissions::TASKS_VIEW_OWN) && $task->assignee_id === $user->getKey());
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::TASKS_CREATE);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can(Permissions::TASKS_UPDATE);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can(Permissions::TASKS_DELETE);
    }

    public function assign(User $user, Task $task): bool
    {
        return $user->can(Permissions::TASKS_ASSIGN);
    }

    /**
     * BR-35: tasks.change_status (any task), or tasks.change_status_own for the assignee, or the creator
     * of a task made for someone else (the creator rule still needs tasks.create).
     */
    public function changeStatus(User $user, Task $task): bool
    {
        if ($user->can(Permissions::TASKS_CHANGE_STATUS)) {
            return true;
        }

        if ($user->can(Permissions::TASKS_CHANGE_STATUS_OWN) && $task->assignee_id === $user->getKey()) {
            return true;
        }

        return $user->can(Permissions::TASKS_CREATE)
            && $task->created_by === $user->getKey()
            && $task->assignee_id !== null
            && $task->assignee_id !== $user->getKey();
    }
}
