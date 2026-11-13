<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;

/**
 * [ASSUMED D-19] Settles the status of a user's salary rows for a date: rows dated after the date are
 * pending; among the rest the latest is active and every earlier one superseded. Idempotent, and it
 * only touches `status` (BR-29), so back-dated rows land in history without replacing the current one.
 */
class SalaryLedger
{
    public function settle(int $userId, ?\DateTimeInterface $today = null): void
    {
        $today = ($today ?? now())->format('Y-m-d');

        $rows = Salary::query()->where('user_id', $userId)->orderBy('effective_date')->orderBy('id')->get();
        $latestInForce = $rows->filter(fn (Salary $s) => $s->effective_date->toDateString() <= $today)->last();

        foreach ($rows as $row) {
            $status = match (true) {
                $row->effective_date->toDateString() > $today => SalaryStatus::Pending,
                $row->is($latestInForce) => SalaryStatus::Active,
                default => SalaryStatus::Superseded,
            };

            if ($row->status !== $status) {
                $row->update(['status' => $status]);
            }
        }
    }
}
