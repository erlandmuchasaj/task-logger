<?php

declare(strict_types=1);

namespace App\Domain\Work\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Work\Models\TimesheetLock;

final class TimesheetLockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::TIMESHEETS_LOCK);
    }

    public function view(User $user, TimesheetLock $lock): bool
    {
        return $user->can(Permissions::TIMESHEETS_LOCK);
    }

    public function lock(User $user): bool
    {
        return $user->can(Permissions::TIMESHEETS_LOCK);
    }
}
