<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Data\ExpenseData;
use App\Domain\Expenses\Models\Expense;
use App\Domain\Expenses\Models\ExpenseLine;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Changes a draft: header, lines and receipt. Any other status is closed to edits, root included (D-52). */
final class UpdateExpense
{
    use ValidatesExpense;

    public function handle(User $actor, Expense $expense, ExpenseData $data): Expense
    {
        Gate::forUser($actor)->authorize('update', $expense);

        $lines = $this->prepareLines($expense->employee, $data);
        $oldReceipt = null;

        $updated = resolve(CauserResolver::class)->withCauser($actor, function () use ($expense, $data, $lines, &$oldReceipt): Expense {
            return DB::transaction(function () use ($expense, $data, $lines, &$oldReceipt): Expense {
                $expense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

                if (! $expense->status->isEditable()) {
                    throw ValidationException::withMessages(['status' => 'Only a draft expense can be changed.']);
                }

                if ($expense->receipt_path !== null && $expense->receipt_path !== $data->receiptPath) {
                    $oldReceipt = $expense->receipt_path;
                }

                $expense->update([
                    'project_id' => $data->projectId,
                    'category' => $data->category,
                    'description' => mb_trim($data->description),
                    'receipt_path' => $data->receiptPath,
                    'notes' => filled($data->notes) ? $data->notes : null,
                ]);

                $expense->lines()->get()->each(fn (ExpenseLine $line) => $line->delete());

                foreach ($lines as $line) {
                    ExpenseLine::query()->create(['expense_id' => $expense->getKey(), ...$line]);
                }

                return $expense->load('lines');
            });
        });

        if ($oldReceipt !== null) {
            Storage::disk((string) config('task-logger.expenses.disk'))->delete($oldReceipt);
        }

        return $updated;
    }
}
