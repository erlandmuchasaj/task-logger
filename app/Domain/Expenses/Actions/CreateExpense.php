<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Data\ExpenseData;
use App\Domain\Expenses\Enums\ExpenseStatus;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * FR-38: finance (or the superadmin) records an expense for an employee, as a draft. The number is EXP- plus the row id,
 * set in the same transaction, so it is unique and race-safe without a counter table (D-52).
 */
final class CreateExpense
{
    use ValidatesExpense;

    public function handle(User $actor, User $employee, ExpenseData $data): Expense
    {
        Gate::forUser($actor)->authorize('create', Expense::class);

        $lines = $this->prepareLines($employee, $data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Expense => DB::transaction(function () use ($actor, $employee, $data, $lines): Expense {
            // Written without events: the audit entry is added below, once the real number is known.
            $expense = Expense::withoutEvents(fn (): Expense => Expense::query()->create([
                'user_id' => $employee->getKey(),
                'created_by' => $actor->getKey(),
                'project_id' => $data->projectId,
                'category' => $data->category,
                'description' => mb_trim($data->description),
                'number' => 'TMP-'.Str::random(12),
                'receipt_path' => $data->receiptPath,
                'notes' => filled($data->notes) ? $data->notes : null,
                'status' => ExpenseStatus::Draft,
            ]));

            $expense->forceFill(['number' => 'EXP-'.mb_str_pad((string) $expense->getKey(), 6, '0', STR_PAD_LEFT)]);
            Expense::withoutEvents(fn () => $expense->save());

            activity()->performedOn($expense)->event('created')
                ->withChanges(['attributes' => $expense->only(['number', 'user_id', 'category', 'description', 'status', 'project_id'])])
                ->log('created');

            foreach ($lines as $line) {
                ExpenseLine::query()->create(['expense_id' => $expense->getKey(), ...$line]);
            }

            return $expense->load('lines');
        }));
    }
}
