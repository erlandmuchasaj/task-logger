<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * D-53: the person replaces the temporary password they were emailed with one of their own. Clears the
 * forced-change flag. The audit records the event, never the value.
 */
class ChangeOwnPassword
{
    public function handle(User $user, #[SensitiveParameter] string $password): User
    {
        if (Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the temporary one.']);
        }

        $user->forceFill(['password' => $password, 'must_change_password' => false])->saveQuietly();

        activity()->causedBy($user)->performedOn($user)->event('password_changed')->log('password_changed');

        return $user->refresh();
    }
}
