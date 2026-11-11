<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Deactivates login with cybercog/laravel-ban (D-20). Perm employees.deactivate (superadmin). History stays. */
class DeactivateEmployee
{
    public function handle(User $actor, User $employee, ?string $comment = null): User
    {
        Gate::forUser($actor)->authorize('deactivate', $employee);

        if ($actor->is($employee)) {
            throw ValidationException::withMessages(['employee' => 'You cannot deactivate yourself.']);
        }

        $isSuperadmin = $employee->roles()->where('name', RoleName::Superadmin->value)->exists();
        $otherSuperadmin = User::role(RoleName::Superadmin->value)->whereKeyNot($employee->getKey())->whereNull('banned_at')->exists();

        if ($isSuperadmin && ! $otherSuperadmin) {
            throw ValidationException::withMessages(['employee' => 'The last superadmin cannot be deactivated.']);
        }

        if ($employee->isBanned()) {
            return $employee;
        }

        $employee->ban(['comment' => $comment, 'created_by_type' => $actor->getMorphClass(), 'created_by_id' => $actor->getKey()]);
        DB::table('sessions')->where('user_id', $employee->getKey())->delete();

        activity()->causedBy($actor)->performedOn($employee)->event('deactivated')->log('deactivated');

        return $employee->refresh();
    }
}
