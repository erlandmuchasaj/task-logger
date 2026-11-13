<?php

declare(strict_types=1);

namespace App\Domain\Clients\Policies;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

final class ClientTechnologyRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW_RATES);
    }

    public function view(User $user, ClientTechnologyRate $rate): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW_RATES);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CLIENTS_UPDATE) && $user->can(Permissions::CLIENTS_VIEW_RATES);
    }

    public function update(User $user, ClientTechnologyRate $rate): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, ClientTechnologyRate $rate): bool
    {
        return $this->create($user);
    }
}
