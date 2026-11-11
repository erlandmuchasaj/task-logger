<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;

/**
 * BR-30: a project manager must hold the admin role. This is a data rule about who may be picked as a
 * manager, not an access check, so it is the one place where application code reads a role name.
 * Access is still decided by permissions only (the architecture test allow-lists this file alone).
 */
final class ProjectManagerEligibility
{
    public static function isEligible(?User $user): bool
    {
        return $user !== null && $user->hasRole(RoleName::Admin->value) && ! $user->isBanned();
    }

    /** @return list<int> ids of users who can be picked as a manager */
    public static function eligibleIds(): array
    {
        return User::role(RoleName::Admin->value)->whereNull('banned_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
