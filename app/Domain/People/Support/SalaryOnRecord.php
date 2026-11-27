<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\People\Models\Salary;
use Carbon\CarbonInterface;

/**
 * D-92: whether a person has a salary on record for a date. Any status counts, because Financials (D-91) prices a day
 * with the latest row dated on or before it whatever its status; `Salary::currentOn` needs an active row, and a
 * pending one only turns active in the daily job, which would refuse a log on the effective date itself.
 */
final class SalaryOnRecord
{
    public static function covers(int $userId, CarbonInterface $date): bool
    {
        return Salary::query()
            ->where('user_id', $userId)
            ->whereDate('effective_date', '<=', $date->toDateString())
            ->exists();
    }
}
