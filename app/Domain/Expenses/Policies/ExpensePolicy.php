<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Policies;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;

/**
 * Permission-only (SPEC 4.1, D-51): finance and superadmin hold every expenses.* permission, nobody else holds any.
 * What an expense's status allows (draft-only edits, no self-approval) is checked in the actions so root cannot skip it.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EXPENSES_VIEW);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::EXPENSES_CREATE);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_UPDATE);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->can(Permissions::EXPENSES_DELETE);
    }

    /** Submit and reopen need update; approve and reject need approve; reimburse needs reimburse. */
    public function transition(User $user, Expense $expense, ExpenseStatus $to): bool
    {
        return $user->can(match ($to) {
            ExpenseStatus::Approved, ExpenseStatus::Rejected => Permissions::EXPENSES_APPROVE,
            ExpenseStatus::Reimbursed => Permissions::EXPENSES_REIMBURSE,
            ExpenseStatus::Draft, ExpenseStatus::Submitted => Permissions::EXPENSES_UPDATE,
        });
    }

    public function restore(User $user, Expense $expense): bool
    {
        return false;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
