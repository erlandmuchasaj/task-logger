<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * D-52: draft to submitted; submitted to approved or rejected; approved to reimbursed; rejected back to draft.
 * Which permission a move needs is the policy's `transition`. Nobody approves or rejects an expense made for
 * themselves, root included (like BR-20). A rejection needs a reason, kept in `notes` (SPEC 5.6 has no review column).
 */
final class ChangeExpenseStatus
{
    public function handle(User $actor, Expense $expense, ExpenseStatus $to, ?string $reason = null): Expense
    {
        Gate::forUser($actor)->authorize('transition', [$expense, $to]);

        $reason = filled($reason) ? mb_trim($reason) : null;

        if ($to === ExpenseStatus::Rejected && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'A rejection needs a reason.']);
        }

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $expense, $to, $reason): Expense {
            $expense = Expense::query()->with('lines')->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($to, $expense->status->next(), true)) {
                throw ValidationException::withMessages(['status' => 'An expense cannot move from '.$expense->status->getLabel().' to '.$to->getLabel().'.']);
            }

            if (in_array($to, [ExpenseStatus::Approved, ExpenseStatus::Rejected], true) && $expense->user_id === $actor->getKey()) {
                throw ValidationException::withMessages(['status' => 'You cannot approve or reject an expense made for yourself.']);
            }

            if ($to === ExpenseStatus::Submitted && $expense->lines->isEmpty()) {
                throw ValidationException::withMessages(['status' => 'Add at least one line before submitting.']);
            }

            $attributes = ['status' => $to];

            if ($to === ExpenseStatus::Rejected) {
                $attributes['notes'] = mb_trim(($expense->notes !== null ? $expense->notes."\n" : '').'Rejected: '.$reason);
            }

            $expense->update($attributes);

            return $expense;
        }));
    }
}
