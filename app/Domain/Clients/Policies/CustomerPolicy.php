<?php

declare(strict_types=1);

namespace App\Domain\Clients\Policies;

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

/** Hourly rates and the payment plan are visible only with clients.view_rates (D-05). */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::CLIENTS_CREATE);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->can(Permissions::CLIENTS_UPDATE);
    }

    public function activate(User $user, Customer $customer): bool
    {
        return $user->can(Permissions::CLIENTS_ACTIVATE);
    }

    public function deactivate(User $user, Customer $customer): bool
    {
        return $user->can(Permissions::CLIENTS_DEACTIVATE);
    }

    public function viewRates(User $user, ?Customer $customer = null): bool
    {
        return $user->can(Permissions::CLIENTS_VIEW_RATES);
    }

    public function manageRates(User $user, Customer $customer): bool
    {
        return $user->can(Permissions::CLIENTS_UPDATE) && $user->can(Permissions::CLIENTS_VIEW_RATES);
    }

    /** Clients are never hard-deleted: deactivate them instead (BR-31). */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
