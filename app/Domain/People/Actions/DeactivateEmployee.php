<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Support\Deactivation;
use Illuminate\Support\Facades\Gate;

/** Deactivates login with cybercog/laravel-ban (D-20). Perm employees.deactivate (superadmin). History stays. */
final class DeactivateEmployee
{
    public function handle(User $actor, User $employee, ?string $comment = null): User
    {
        Gate::forUser($actor)->authorize('deactivate', $employee);

        return Deactivation::ban($actor, $employee, $comment);
    }
}
