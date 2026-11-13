<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way a login is switched off (D-20, D-29, D-32): ban, end the sessions, audit. Used by the
 * superadmin's Deactivate action and by completing an offboarding (ADR-0008). Authorisation happens in the caller.
 */
final class Deactivation
{
    /**
     * @param  array<string, mixed>  $properties  extra audit context, for example the offboarding id
     *
     * @throws ValidationException
     */
    public static function ban(User $actor, User $employee, ?string $comment = null, array $properties = []): User
    {
        if ($actor->is($employee)) {
            throw ValidationException::withMessages(['employee' => 'You cannot deactivate yourself.']);
        }

        $isSuperadmin = $employee->roles()->where('name', RoleName::Superadmin->value)->exists();
        $otherSuperadmin = User::query()->role(RoleName::Superadmin->value)->whereKeyNot($employee->getKey())->whereNull('banned_at')->exists();

        if ($isSuperadmin && ! $otherSuperadmin) {
            throw ValidationException::withMessages(['employee' => 'The last superadmin cannot be deactivated.']);
        }

        if ($employee->isBanned()) {
            return $employee;
        }

        $employee->ban(['comment' => $comment, 'created_by_type' => $actor->getMorphClass(), 'created_by_id' => $actor->getKey()]);
        DB::table('sessions')->where('user_id', $employee->getKey())->delete();

        activity()->causedBy($actor)->performedOn($employee)->event('deactivated')->withProperties($properties)->log('deactivated');

        return $employee->refresh();
    }
}
