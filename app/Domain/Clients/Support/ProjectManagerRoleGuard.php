<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Contracts\BlocksRoleChange;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;

/** BR-30: a user who manages a project must keep the admin role. */
final class ProjectManagerRoleGuard implements BlocksRoleChange
{
    public function reasonToBlock(User $user, RoleName $newRole): ?string
    {
        if ($newRole === RoleName::Admin) {
            return null;
        }

        $count = Project::query()->where('manager_id', $user->getKey())->count();

        return $count > 0
            ? "This person manages {$count} project(s) and must keep the admin role. Assign another manager first."
            : null;
    }
}
