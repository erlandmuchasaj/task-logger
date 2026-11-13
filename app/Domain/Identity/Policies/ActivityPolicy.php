<?php

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use Spatie\Activitylog\Models\Activity;

/** The audit trail is read-only and visible to activity_logs.view holders (superadmin). */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::ACTIVITY_LOGS_VIEW);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can(Permissions::ACTIVITY_LOGS_VIEW);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
