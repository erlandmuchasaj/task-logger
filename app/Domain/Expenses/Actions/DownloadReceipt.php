<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Models\Expense;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The only way to read a receipt: permission checked, from the private disk, no public URL (D-52). */
class DownloadReceipt
{
    public function handle(User $actor, Expense $expense): StreamedResponse
    {
        Gate::forUser($actor)->authorize('view', $expense);

        $disk = Storage::disk((string) config('task-logger.expenses.disk'));

        if ($expense->receipt_path === null || ! $disk->exists($expense->receipt_path)) {
            throw ValidationException::withMessages(['receipt' => 'This expense has no receipt.']);
        }

        return $disk->download($expense->receipt_path, $expense->number.'.'.pathinfo($expense->receipt_path, PATHINFO_EXTENSION));
    }
}
