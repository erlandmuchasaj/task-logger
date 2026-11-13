<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\CarryOverTranches;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Work\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * BR-18: carried-over days that are still unused on their `expires_on` date expire through a negative
 * ledger entry. Days used since the carry-over are taken from the oldest carried part first
 * ({@see CarryOverTranches}). The ledger is append-only and allows one expiry entry per person, type and
 * date, so every tranche due on a date shares one entry. A tranche due on 1 January is settled by the
 * year-end close of {@see GrantAnnualLeave}, which owns that day's entry: this job runs the close first.
 * Idempotent.
 */
final class ExpireCarryOver
{
    /** @return int number of expiry entries written */
    public function handle(): int
    {
        $written = 0;

        LeaveLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::CarryOver)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDay()->toDateString())
            ->select('user_id', 'leave_type_id', 'expires_on')
            ->distinct()
            ->get()
            ->each(function (LeaveLedgerEntry $due) use (&$written): void {
                $user = User::query()->with('country')->find($due->user_id);

                if ($user === null || WorkCalendar::today($user)->lessThan($due->expires_on)) {
                    return;
                }

                // 1 January: the year-end close writes that day's single expiry entry, so it goes first.
                if ($due->expires_on->month === 1 && $due->expires_on->day === 1) {
                    resolve(GrantAnnualLeave::class)->grantFor($user);
                }

                $written += $this->expireOn($user, (int) $due->leave_type_id, $due->expires_on);
            });

        return $written;
    }

    /** Expires the tranches of one person and type that fell due before `$before` and are not expired yet. */
    public function expireDueFor(User $user, int $leaveTypeId, CarbonInterface $before): int
    {
        $written = 0;

        LeaveLedgerEntry::query()
            ->where('user_id', $user->getKey())->where('leave_type_id', $leaveTypeId)
            ->where('entry_type', LedgerEntryType::CarryOver)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', $before->toDateString())
            ->select('user_id', 'leave_type_id', 'expires_on')
            ->distinct()
            ->get()
            ->each(function (LeaveLedgerEntry $due) use ($user, $leaveTypeId, &$written): void {
                $written += $this->expireOn($user, $leaveTypeId, $due->expires_on);
            });

        return $written;
    }

    public function hasExpiryOn(int $userId, int $leaveTypeId, CarbonInterface $date): bool
    {
        return LeaveLedgerEntry::query()
            ->where('user_id', $userId)->where('leave_type_id', $leaveTypeId)
            ->where('entry_type', LedgerEntryType::Expiry)
            ->whereDate('effective_on', $date->toDateString())
            ->exists();
    }

    private function expireOn(User $user, int $leaveTypeId, CarbonInterface $date): int
    {
        return DB::transaction(function () use ($user, $leaveTypeId, $date): int {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($this->hasExpiryOn((int) $user->getKey(), $leaveTypeId, $date)) {
                return 0;
            }

            $due = (int) CarryOverTranches::remaining((int) $user->getKey(), $leaveTypeId, $date)
                ->filter(fn (array $tranche): bool => $tranche['entry']->expires_on->toDateString() === $date->toDateString())
                ->sum('remaining');

            // Never expire more than the balance holds.
            $remaining = min($due, max(LeaveBalance::balance($user, $leaveTypeId), 0));

            if ($remaining <= 0) {
                return 0;
            }

            LeaveLedgerEntry::query()->create([
                'user_id' => $user->getKey(), 'leave_type_id' => $leaveTypeId, 'entry_type' => LedgerEntryType::Expiry,
                'days' => Days::format(-$remaining), 'effective_on' => $date->toDateString(),
                'note' => 'Carried-over days expired',
            ]);

            return 1;
        });
    }
}
