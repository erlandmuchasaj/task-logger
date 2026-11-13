<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Models\Performance;

/**
 * BR-27, D-21, ADR-0007: performances.view (superadmin, hr) reads all; a direct manager reads their reports'
 * reviews through the manager_id relationship, with no permission needed. Writing is performances.create
 * (superadmin, admin), and an admin who is not the manager can create but not read it back (FR-15).
 * Reviews are never edited or deleted.
 */
final class PerformancePolicy
{
    /** Open the list: readers, managers with reports, and creators (who see an empty list but can add). */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PERFORMANCES_VIEW)
            || $user->can(Permissions::PERFORMANCES_CREATE)
            || $user->directReports()->exists();
    }

    public function view(User $user, Performance $performance): bool
    {
        if ($user->can(Permissions::PERFORMANCES_VIEW)) {
            return true;
        }

        $managerId = $performance->relationLoaded('employee') ? $performance->employee->manager_id : $performance->employee()->value('manager_id');

        return $managerId !== null && $managerId === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::PERFORMANCES_CREATE);
    }

    public function update(User $user, Performance $performance): bool
    {
        return false;
    }

    public function delete(User $user, Performance $performance): bool
    {
        return false;
    }

    public function restore(User $user, Performance $performance): bool
    {
        return false;
    }

    public function forceDelete(User $user, Performance $performance): bool
    {
        return false;
    }
}
