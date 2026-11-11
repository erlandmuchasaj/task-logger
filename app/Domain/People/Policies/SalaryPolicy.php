<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\People\Models\Salary;

/** BR-29: visible to salaries.view holders; created by salaries.manage; never edited or deleted. */
class SalaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::SALARIES_VIEW);
    }

    public function view(User $user, Salary $salary): bool
    {
        return $user->can(Permissions::SALARIES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::SALARIES_MANAGE);
    }

    public function update(User $user, Salary $salary): bool
    {
        return false;
    }

    public function delete(User $user, Salary $salary): bool
    {
        return false;
    }
}
