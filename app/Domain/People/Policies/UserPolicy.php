<?php

declare(strict_types=1);

namespace App\Domain\People\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

/**
 * Permission-only (SPEC 4.1). The directory is a separate, field-limited surface (BR-26); this policy
 * guards the management screens and the private HR record. A user has no extra right over their own
 * HR record (D-14 default).
 */
class UserPolicy
{
    /** Open the employee management list: anyone who may see or change HR data or accounts. */
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEES_VIEW_HR_RECORD)
            || $user->can(Permissions::EMPLOYEES_CREATE)
            || $user->can(Permissions::EMPLOYEES_UPDATE);
    }

    public function view(User $user, User $employee): bool
    {
        return $this->viewHrRecord($user, $employee);
    }

    public function viewHrRecord(User $user, User $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_VIEW_HR_RECORD);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEES_CREATE);
    }

    /** Open the edit page: account or HR record rights. */
    public function update(User $user, User $employee): bool
    {
        return $this->updateAccount($user, $employee) || $this->updateHrRecord($user, $employee);
    }

    public function updateAccount(User $user, User $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_UPDATE);
    }

    public function updateHrRecord(User $user, User $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_UPDATE_HR_RECORD);
    }

    public function viewSalaries(User $user, User $employee): bool
    {
        return $user->can(Permissions::SALARIES_VIEW);
    }

    public function manageSalaries(User $user, User $employee): bool
    {
        return $user->can(Permissions::SALARIES_MANAGE);
    }

    public function deactivate(User $user, User $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_DEACTIVATE);
    }

    public function resetTwoFactor(User $user, User $employee): bool
    {
        return $user->can(Permissions::TWO_FACTOR_MANAGE);
    }

    /** Users are never hard-deleted: business history survives (SPEC 5.0). Deactivate instead. */
    public function delete(User $user, User $employee): bool
    {
        return false;
    }

    public function restore(User $user, User $employee): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $employee): bool
    {
        return false;
    }
}
