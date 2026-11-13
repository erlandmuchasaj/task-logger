<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\CarryOverTranches;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\Entitlement;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\Work\Support\WorkCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BR-18 / FR-32: a grant per person and balance-affecting leave type from the policy active on the grant
 * date. It is due on 1 January in the person's country timezone, or on the start date in the year someone
 * joins (prorated). A new year first closes the old one: the unused balance expires, except the part the
 * policy lets you carry over. Safe to run any number of times (NFR-13): a year is granted once.
 */
final class GrantAnnualLeave
{
    /** @return int number of grant entries written */
    public function handle(?User $only = null): int
    {
        $written = 0;

        User::query()
            ->whereNull('banned_at')
            ->whereNull('anonymized_at')
            ->whereNotNull('country_id')
            ->whereNotNull('start_date')
            ->when($only, fn ($query) => $query->whereKey($only->getKey()))
            ->with('country')
            ->each(function (User $user) use (&$written): void {
                $written += $this->grantFor($user);
            });

        return $written;
    }

    public function grantFor(User $user): int
    {
        $today = WorkCalendar::today($user);
        $start = CarbonImmutable::parse($user->start_date->toDateString());

        if ($start->greaterThan($today)) {
            return 0;
        }

        $year = $today->year;
        $joinedThisYear = $start->year === $year;
        $grantDate = $joinedThisYear ? $start : CarbonImmutable::create($year, 1, 1);

        return DB::transaction(function () use ($user, $year, $joinedThisYear, $grantDate, $start): int {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $written = 0;

            foreach (LeaveType::query()->where('affects_balance', true)->where('is_active', true)->get() as $type) {
                $policy = CountryLeavePolicy::query()->activeOn((int) $user->country_id, (int) $type->getKey(), $grantDate)->first();

                $alreadyGranted = LeaveLedgerEntry::query()
                    ->where('user_id', $user->getKey())->where('leave_type_id', $type->getKey())
                    ->where('entry_type', LedgerEntryType::Grant)
                    ->whereBetween('effective_on', [$year.'-01-01', $year.'-12-31'])
                    ->exists();

                if ($policy === null || $alreadyGranted) {
                    continue;
                }

                if (! $joinedThisYear) {
                    $this->closePreviousYear($user, $type, $grantDate, $policy);
                }

                $tenths = Entitlement::grantTenths($policy, $start, $joinedThisYear);

                if ($tenths > 0) {
                    LeaveLedgerEntry::query()->create([
                        'user_id' => $user->getKey(),
                        'leave_type_id' => $type->getKey(),
                        'entry_type' => LedgerEntryType::Grant,
                        'days' => Days::format($tenths),
                        'effective_on' => $grantDate->toDateString(),
                        'note' => $joinedThisYear ? 'Prorated grant for the year of joining' : 'Annual grant '.$year,
                    ]);
                    $written++;
                }
            }

            return $written;
        });
    }

    /**
     * What is left from last year either expires or, when the policy allows, carries over up to its maximum.
     * Carry-over tranches keep their own expiry date ({@see CarryOverTranches}): one that is still live
     * stays where it is and counts towards the cap; one due on the new year expires with the balance and
     * is never carried again; the rest of the balance is what the policy may carry over.
     */
    private function closePreviousYear(User $user, LeaveType $type, CarbonImmutable $newYear, CountryLeavePolicy $policy): void
    {
        $expire = resolve(ExpireCarryOver::class);

        if ($expire->hasExpiryOn((int) $user->getKey(), (int) $type->getKey(), $newYear)) {
            return; // only a close writes a 1 January expiry (ExpireCarryOver leaves that day to it): already closed
        }

        $expire->expireDueFor($user, (int) $type->getKey(), $newYear);

        $leftover = Days::tenths((string) (LeaveLedgerEntry::query()
            ->where('user_id', $user->getKey())->where('leave_type_id', $type->getKey())
            ->whereDate('effective_on', '<', $newYear->toDateString())
            ->sum('days') ?: '0'));

        if ($leftover <= 0) {
            return;
        }

        $tranches = CarryOverTranches::remaining((int) $user->getKey(), (int) $type->getKey(), $newYear);
        $live = min($leftover, (int) $tranches->filter(fn (array $tranche): bool => $tranche['entry']->expires_on->greaterThan($newYear))->sum('remaining'));
        $due = (int) $tranches->filter(fn (array $tranche): bool => $tranche['entry']->expires_on->isSameDay($newYear))->sum('remaining');
        $expiring = $leftover - $live;
        $carry = 0;

        if ($policy->carry_over_enabled) {
            $room = $policy->carry_over_max_days === null ? PHP_INT_MAX : Days::tenths((string) $policy->carry_over_max_days) - $live;
            $carry = max(min($expiring - min($due, $expiring), $room), 0);
        }

        if ($expiring > 0) {
            LeaveLedgerEntry::query()->create([
                'user_id' => $user->getKey(), 'leave_type_id' => $type->getKey(), 'entry_type' => LedgerEntryType::Expiry,
                'days' => Days::format(-$expiring), 'effective_on' => $newYear->toDateString(),
                'note' => 'Year-end: unused balance of '.($newYear->year - 1),
            ]);
        }

        if ($carry > 0) {
            LeaveLedgerEntry::query()->create([
                'user_id' => $user->getKey(), 'leave_type_id' => $type->getKey(), 'entry_type' => LedgerEntryType::CarryOver,
                'days' => Days::format($carry), 'effective_on' => $newYear->toDateString(),
                'expires_on' => $policy->carry_over_expires_after_months !== null ? $newYear->addMonths($policy->carry_over_expires_after_months)->toDateString() : null,
                'note' => 'Carried over from '.($newYear->year - 1),
            ]);
        }
    }
}
