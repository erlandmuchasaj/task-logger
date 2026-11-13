<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Notifications\PasswordResetByAdmin;
use App\Domain\People\Support\TemporaryPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * D-53: the administrator replaces a person's password with a new temporary one and emails it to them.
 * Perm employees.update (the same right that resends the set-password link). The audit records the
 * event, never the value.
 */
class ResetEmployeePassword
{
    public function handle(User $actor, User $employee): User
    {
        Gate::forUser($actor)->authorize('resetPassword', $employee);

        if ($employee->isBanned() || $employee->anonymized_at !== null) {
            throw ValidationException::withMessages(['employee' => 'A deactivated or anonymised account cannot get a new password.']);
        }

        $password = TemporaryPassword::generate();

        $employee->forceFill(['password' => $password, 'remember_token' => null, 'must_change_password' => true])->saveQuietly();

        activity()->causedBy($actor)->performedOn($employee)->event('password_reset_by_admin')->log('password_reset_by_admin');

        $employee->notify(new PasswordResetByAdmin($password));

        return $employee->refresh();
    }
}
