<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Models\Offboarding;

/** Permission-only (SPEC 4.1): offboardings.view and offboardings.manage (superadmin, admin, hr). Offboardings are cancelled, never deleted. */
final class OffboardingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::OFFBOARDINGS_VIEW);
    }

    public function view(User $user, Offboarding $offboarding): bool
    {
        return $user->can(Permissions::OFFBOARDINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::OFFBOARDINGS_MANAGE);
    }

    public function update(User $user, Offboarding $offboarding): bool
    {
        return $user->can(Permissions::OFFBOARDINGS_MANAGE);
    }

    public function changeStatus(User $user, Offboarding $offboarding): bool
    {
        return $user->can(Permissions::OFFBOARDINGS_MANAGE);
    }

    public function delete(User $user, Offboarding $offboarding): bool
    {
        return false;
    }

    public function restore(User $user, Offboarding $offboarding): bool
    {
        return false;
    }

    public function forceDelete(User $user, Offboarding $offboarding): bool
    {
        return false;
    }
}
