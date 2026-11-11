<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;

/**
 * D-09: exactly one role per user. Replaces any existing role and audits the change (BR-32).
 */
class AssignUserRole
{
    public function handle(User $user, RoleName $role): User
    {
        $before = $user->roles()->pluck('name')->all();

        if ($before === [$role->value]) {
            return $user;
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
