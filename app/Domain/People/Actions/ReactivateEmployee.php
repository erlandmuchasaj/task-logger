<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class ReactivateEmployee
{
    public function handle(User $actor, User $employee): User
    {
        Gate::forUser($actor)->authorize('deactivate', $employee);

        if ($employee->anonymized_at !== null || ! $employee->isBanned()) {
            return $employee;
        }

        $employee->unban();

        activity()->causedBy($actor)->performedOn($employee)->event('reactivated')->log('reactivated');

        return $employee->refresh();
    }
}
