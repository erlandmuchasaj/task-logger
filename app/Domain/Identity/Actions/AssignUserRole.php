<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Contracts\BlocksRoleChange;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * D-09: exactly one role per user. Replaces any existing role and audits the change (BR-32).
 */
class AssignUserRole
{
    /** @param  iterable<BlocksRoleChange>  $guards  other modules' vetoes (BR-30) */
    public function __construct(private readonly iterable $guards = []) {}

    public function handle(User $user, RoleName $role): User
    {
        $before = $user->roles()->pluck('name')->all();

        if ($before === [$role->value]) {
            return $user;
        }

        foreach ($this->guards as $guard) {
            if ($reason = $guard->reasonToBlock($user, $role)) {
                throw ValidationException::withMessages(['role' => $reason]);
            }
        }

        $user->syncRoles([$role->value]);

        activity()
            ->performedOn($user)
            ->withChanges(['old' => ['roles' => $before], 'attributes' => ['roles' => [$role->value]]])
            ->event('role_changed')
            ->log('role_changed');

        return $user->refresh();
    }
}
