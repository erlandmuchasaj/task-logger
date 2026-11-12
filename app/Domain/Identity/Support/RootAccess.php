<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Clients\Models\Customer;
use App\Domain\Exports\Models\Export;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Models\Performance;
use App\Domain\People\Models\Salary;
use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Activitylog\Models\Activity;

/**
 * Root access (D-41). Whoever holds the permission `system.root` (the superadmin) is allowed every
 * ability through a before-gate, so the superadmin can edit and manage everything.
 *
 * It is keyed on a permission, not on a role name, so authorisation stays permission-first (SPEC 4.1).
 * It checks the permission directly (not through the Gate) so it cannot call itself.
 *
 * A few abilities are NOT overridden because the spec makes them impossible on purpose or ties them to
 * their owner; root falls through to the policy for these, which denies them:
 *  - the audit trail is read-only (BR-32);
 *  - salary history is append-only (BR-29);
 *  - users and clients are never hard-deleted, they are deactivated (SPEC 5.0, BR-31);
 *  - an export file belongs to the person who requested it (BR-33);
 *  - performance reviews are never edited or deleted, and offboardings are cancelled, not deleted (D-45).
 */
final class RootAccess
{
    /** @var array<class-string, list<string>> model => abilities that stay under their policy */
    private const POLICY_ONLY = [
        Activity::class => ['create', 'update', 'delete', 'restore', 'forceDelete'],
        Salary::class => ['update', 'delete', 'restore', 'forceDelete'],
        User::class => ['delete', 'restore', 'forceDelete'],
        Customer::class => ['delete', 'restore', 'forceDelete'],
        Export::class => ['view', 'download', 'create', 'update', 'delete'],
        Performance::class => ['update', 'delete', 'restore', 'forceDelete'],
        Offboarding::class => ['delete', 'restore', 'forceDelete'],
    ];

    /**
     * Gate::before result: true to grant, null to let the policy decide.
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function check(Authenticatable $user, string $ability, array $arguments): ?bool
    {
        if (! $user instanceof User || ! $user->hasPermissionTo(Permissions::SYSTEM_ROOT)) {
            return null;
        }

        return self::staysUnderPolicy($ability, $arguments) ? null : true;
    }

    /** @param  array<int, mixed>  $arguments */
    private static function staysUnderPolicy(string $ability, array $arguments): bool
    {
        $subject = $arguments[0] ?? null;
        $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);

        if ($class === null) {
            return false;
        }

        foreach (self::POLICY_ONLY as $model => $abilities) {
            if (is_a($class, $model, true) && in_array($ability, $abilities, true)) {
                return true;
            }
        }

        return false;
    }
}
