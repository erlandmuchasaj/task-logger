<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Clients\Models\Project;
use App\Domain\Expenses\Data\ExpenseData;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Server-side rules for an expense and its lines (D-52, BR-12). */
trait ValidatesExpense
{
    /**
     * Validates the input and computes each line's amount on the server.
     *
     * @return list<array{description: string, quantity: int, unit_price_minor: int, amount_minor: int, currency_code: string, date: string}>
     *
     * @throws ValidationException
     */
    protected function prepareLines(User $employee, ExpenseData $data): array
    {
        if ($employee->anonymized_at !== null) {
            throw ValidationException::withMessages(['user_id' => 'This employee has been anonymised.']);
        }

        if (trim($data->description) === '') {
            throw ValidationException::withMessages(['description' => 'Describe the expense.']);
        }

        if (! preg_match('/^[A-Za-z]{3}$/', $data->currency)) {
            throw ValidationException::withMessages(['currency' => 'Use a three-letter ISO currency code.']);
        }

        if ($data->projectId !== null && ! Project::query()->whereKey($data->projectId)->exists()) {
            throw ValidationException::withMessages(['project_id' => 'Choose an existing project.']);
        }

        if ($data->receiptPath !== null && (! str_starts_with($data->receiptPath, 'receipts/') || str_contains($data->receiptPath, '..'))) {
            throw ValidationException::withMessages(['receipt_path' => 'The receipt must be an uploaded file.']);
        }

        if ($data->lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line.']);
        }

        $currency = strtoupper($data->currency);
        $lines = [];

        foreach ($data->lines as $i => $line) {
            if (trim($line->description) === '') {
                throw ValidationException::withMessages(["lines.{$i}.description" => 'Describe the line.']);
            }

            if ($line->quantity < 1) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => 'The quantity must be a whole number of at least 1.']);
            }

            try {
                $unit = Money::fromMajor($line->unitPrice, $currency);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["lines.{$i}.unit_price" => "[{$line->unitPrice}] is not a valid {$currency} amount."]);
            }

            if ($unit->minor <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.unit_price" => 'The price must be above zero.']);
            }

            $lines[] = [
                'description' => trim($line->description),
                'quantity' => $line->quantity,
                'unit_price_minor' => $unit->minor,
                'amount_minor' => $unit->minor * $line->quantity,
                'currency_code' => $currency,
                'date' => $line->date->toDateString(),
            ];
        }

        return $lines;
    }
}
