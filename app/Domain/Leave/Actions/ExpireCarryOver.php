<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Work\Support\WorkCalendar;
use Illuminate\Support\Facades\DB;

/**
 * BR-18: carried-over days that are still unused on their `expires_on` date expire through a negative
 * ledger entry. Days used since the carry-over are taken from the carried part first. Idempotent.
 */
class ExpireCarryOver
{
    /** @return int number of expiry entries written */
    public function handle(): int
    {
        $written = 0;

        LeaveLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::CarryOver)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDay()->toDateString())
            ->with('user.country')
            ->each(function (LeaveLedgerEntry $carry) use (&$written): void {
                $written += $this->expire($carry);
            });

        return $written;
    }

    private function expire(LeaveLedgerEntry $carry): int
    {
        $user = $carry->user;

        if (WorkCalendar::today($user)->lessThan($carry->expires_on)) {
            return 0;
        }

        return DB::transaction(function () use ($carry, $user): int {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $done = LeaveLedgerEntry::query()
                ->where('user_id', $carry->user_id)->where('leave_type_id', $carry->leave_type_id)
                ->where('entry_type', LedgerEntryType::Expiry)
                ->whereDate('effective_on', $carry->expires_on->toDateString())
                ->exists();

            if ($done) {
                return 0;
            }

            $used = -Days::tenths((string) (LeaveLedgerEntry::query()
                ->where('user_id', $carry->user_id)->where('leave_type_id', $carry->leave_type_id)
                ->whereIn('entry_type', [LedgerEntryType::Deduction, LedgerEntryType::Reversal])
                ->whereDate('effective_on', '>=', $carry->effective_on->toDateString())
                ->whereDate('effective_on', '<', $carry->expires_on->toDateString())
                ->sum('days') ?: '0'));

            $remaining = min(
                max(Days::tenths((string) $carry->days) - max($used, 0), 0),
                max(LeaveBalance::balance($carry->user_id, $carry->leave_type_id), 0),
            );

            if ($remaining <= 0) {
                return 0;
            }

            LeaveLedgerEntry::create([
                'user_id' => $carry->user_id, 'leave_type_id' => $carry->leave_type_id, 'entry_type' => LedgerEntryType::Expiry,
                'days' => Days::format(-$remaining), 'effective_on' => $carry->expires_on->toDateString(),
                'note' => 'Carried-over days expired',
            ]);

            return 1;
        });
    }
}
