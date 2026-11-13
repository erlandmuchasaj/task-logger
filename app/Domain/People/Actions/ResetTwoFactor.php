<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

/** For a user who lost their device and recovery codes. Perm two_factor.manage (superadmin). */
class ResetTwoFactor
{
    public function handle(User $actor, User $employee): User
    {
        Gate::forUser($actor)->authorize('resetTwoFactor', $employee);

        $employee->saveAppAuthenticationRecoveryCodes(null);
        $employee->saveAppAuthenticationSecret(null); // audits two_factor_disabled

        activity()->causedBy($actor)->performedOn($employee)->event('two_factor_reset')->log('two_factor_reset');

        return $employee->refresh();
    }
}
