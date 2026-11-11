<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Activate or deactivate a client (clients.activate / clients.deactivate). A deactivated client gets no
 * new projects, tasks or logs (BR-31); existing history stays.
 */
class SetCustomerActive
{
    public function handle(User $actor, Customer $customer, bool $active): Customer
    {
        Gate::forUser($actor)->authorize($active ? 'activate' : 'deactivate', $customer);

        return app(CauserResolver::class)->withCauser($actor, function () use ($customer, $active): Customer {
            $customer->update(['is_active' => $active]);

            return $customer->refresh();
        });
    }
}
