<?php

declare(strict_types=1);

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;

/**
 * Lets another module veto a role change without Identity depending on it (BR-30: a project manager
 * must hold the admin role, also when a role changes). Implementations are tagged `role-change-guards`.
 */
interface BlocksRoleChange
{
    /** A message when the change must be refused, null when it is fine. */
    public function reasonToBlock(User $user, RoleName $newRole): ?string;
}
