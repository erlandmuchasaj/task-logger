<?php

declare(strict_types=1);

namespace App\Domain\Clients\Policies;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

/** The budget is visible only with clients.view_rates (D-05). */
final class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PROJECTS_VIEW);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permissions::PROJECTS_VIEW);
    }

    /** D-49: the estimated-versus-logged report shows everyone's logged time, so it needs both permissions. */
    public function viewReport(User $user): bool
    {
        return $user->can(Permissions::PROJECTS_VIEW) && $user->can(Permissions::LOGS_VIEW_ANY);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PROJECTS_CREATE);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can(Permissions::PROJECTS_UPDATE);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permissions::PROJECTS_DELETE);
    }

    public function changeStatus(User $user, Project $project): bool
    {
        return $user->can(Permissions::PROJECTS_CHANGE_STATUS);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $user->can(Permissions::PROJECTS_MANAGE_MEMBERS);
    }

    public function manageFixedCosts(User $user, Project $project): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }

    public function viewBudget(User $user, Project $project): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW_RATES);
    }
}
