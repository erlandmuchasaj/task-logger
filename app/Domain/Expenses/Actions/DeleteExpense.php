<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Deletes a draft and its receipt file. Submitted expenses are history and are never deleted (D-52). */
final class DeleteExpense
{
    public function handle(User $actor, Expense $expense): void
    {
        Gate::forUser($actor)->authorize('delete', $expense);

        $receipt = null;

        resolve(CauserResolver::class)->withCauser($actor, function () use ($expense, &$receipt): void {
            DB::transaction(function () use ($expense, &$receipt): void {
                $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

                if (! $expense->status->isEditable()) {
                    throw ValidationException::withMessages(['status' => 'Only a draft expense can be deleted.']);
                }

                $receipt = $expense->receipt_path;
                $expense->lines()->get()->each(fn (ExpenseLine $line) => $line->delete());
                $expense->delete();
            });
        });

        if ($receipt !== null) {
            Storage::disk((string) config('task-logger.expenses.disk'))->delete($receipt);
        }
    }
}
