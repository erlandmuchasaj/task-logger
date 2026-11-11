<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;

/** Daily job: pending salaries whose effective date has arrived become active (idempotent). */
class ActivateDueSalaries
{
    public function __construct(private readonly SalaryLedger $ledger) {}

    /** @return int number of employees whose salary history was settled */
    public function handle(): int
    {
        $userIds = Salary::query()
            ->where('status', SalaryStatus::Pending)
            ->whereDate('effective_date', '<=', now()->toDateString())
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $this->ledger->settle((int) $userId);
        }

        return $userIds->count();
    }
}
