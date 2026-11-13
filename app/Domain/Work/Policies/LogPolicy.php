<?php

declare(strict_types=1);

namespace App\Domain\Work\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Support\TimesheetLocks;
use Carbon\CarbonInterface;

/**
 * Permission-first (SPEC 4.1). Open months: people create, edit and delete only their own logs. A locked
 * month (D-13, BR-07): employees cannot touch it at all; people with logs.edit_locked (admin, superadmin)
 * can create their own and correct anyone's. [ASSUMED D-39] The grid has no "edit anyone's log" permission,
 * so editing other people's logs exists only for month-close corrections.
 */
final class LogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::LOGS_VIEW_ANY) || $user->can(Permissions::LOGS_VIEW_OWN);
    }

    public function view(User $user, Log $log): bool
    {
        return $user->can(Permissions::LOGS_VIEW_ANY)
            || ($user->can(Permissions::LOGS_VIEW_OWN) && $log->user_id === $user->getKey());
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::LOGS_CREATE_OWN);
    }

    /** May this user add a log of their own for that date? */
    public function createOn(User $user, CarbonInterface $date): bool
    {
        return $user->can(Permissions::LOGS_CREATE_OWN)
            && (! TimesheetLocks::isLocked($date) || $user->can(Permissions::LOGS_EDIT_LOCKED));
    }

    public function update(User $user, Log $log): bool
    {
        if (TimesheetLocks::isLocked($log->date)) {
            return $user->can(Permissions::LOGS_EDIT_LOCKED);
        }

        return $log->user_id === $user->getKey() && $user->can(Permissions::LOGS_UPDATE_OWN);
    }

    public function delete(User $user, Log $log): bool
    {
        if (TimesheetLocks::isLocked($log->date)) {
            return $user->can(Permissions::LOGS_EDIT_LOCKED);
        }

        return $log->user_id === $user->getKey() && $user->can(Permissions::LOGS_DELETE_OWN);
    }

    public function export(User $user): bool
    {
        return $user->can(Permissions::LOGS_EXPORT);
    }
}
