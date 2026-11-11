<?php

declare(strict_types=1);

namespace App\Domain\Clients\Policies;

use App\Domain\Clients\Models\FixedCost;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

class FixedCostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }

    public function view(User $user, FixedCost $cost): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }

    public function update(User $user, FixedCost $cost): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }

    public function delete(User $user, FixedCost $cost): bool
    {
        return $user->can(Permissions::FIXED_COSTS_MANAGE);
    }
}
