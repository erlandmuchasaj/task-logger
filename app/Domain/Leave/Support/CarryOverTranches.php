<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * BR-18: every carry-over entry with an expiry date is a tranche that lives and expires on its own
 * terms. Days taken are used up from the oldest live tranche first, so the same day is never counted
 * against two tranches (an 18-month carry overlaps the next year's carry). Deductions and reversals
 * are replayed in date order against the tranches that are live on the day they happen.
 */
final class CarryOverTranches
{
    /**
     * What is left of each tranche as of a date, in tenths. Usage on or after `$asOf` is not counted.
     * A tranche that has expired already still reports what was left at its expiry.
     *
     * @return Collection<int, array{entry: LeaveLedgerEntry, remaining: int}> keyed by ledger entry id
     */
    public static function remaining(int $userId, int $leaveTypeId, CarbonInterface $asOf): Collection
    {
        $tranches = LeaveLedgerEntry::query()
            ->where('user_id', $userId)->where('leave_type_id', $leaveTypeId)
            ->where('entry_type', LedgerEntryType::CarryOver)
            ->whereNotNull('expires_on')
            ->whereDate('effective_on', '<', $asOf->toDateString())
            ->oldest('effective_on')->orderBy('id')
            ->get();

        if ($tranches->isEmpty()) {
            return collect();
        }

        $left = $tranches->mapWithKeys(fn (LeaveLedgerEntry $tranche): array => [$tranche->getKey() => Days::tenths((string) $tranche->days)])->all();
        $used = array_fill_keys(array_keys($left), 0);

        $usage = LeaveLedgerEntry::query()
            ->where('user_id', $userId)->where('leave_type_id', $leaveTypeId)
            ->whereIn('entry_type', [LedgerEntryType::Deduction, LedgerEntryType::Reversal])
            ->whereDate('effective_on', '>=', $tranches->min(fn (LeaveLedgerEntry $tranche): string => $tranche->effective_on->toDateString()))
            ->whereDate('effective_on', '<', $asOf->toDateString())
            ->oldest('effective_on')->orderBy('id')
            ->get();

        foreach ($usage as $event) {
            $day = $event->effective_on->toDateString();
            $live = $tranches->filter(fn (LeaveLedgerEntry $tranche): bool => $tranche->effective_on->toDateString() <= $day && $tranche->expires_on->toDateString() > $day);
            $tenths = Days::tenths((string) $event->days);

            if ($tenths < 0) {
                $need = -$tenths;

                foreach ($live as $tranche) {
                    $take = min($need, $left[$tranche->getKey()]);
                    $left[$tranche->getKey()] -= $take;
                    $used[$tranche->getKey()] += $take;
                    $need -= $take;
                }
            } else {
                $give = $tenths;

                foreach ($live->reverse() as $tranche) {
                    $back = min($give, $used[$tranche->getKey()]);
                    $left[$tranche->getKey()] += $back;
                    $used[$tranche->getKey()] -= $back;
                    $give -= $back;
                }
            }
        }

        return $tranches->mapWithKeys(fn (LeaveLedgerEntry $tranche): array => [
            $tranche->getKey() => ['entry' => $tranche, 'remaining' => $left[$tranche->getKey()]],
        ]);
    }
}
