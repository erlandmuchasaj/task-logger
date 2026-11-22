<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\Work\Support\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * D-73: an employee came back to work earlier than the approved leave said. The request ends on the day before
 * `$backAt`, the days after that go back to the balance through a reversal entry, and the status stays approved.
 * Approved is the only status that can be shortened: taken leave is history (BR-19).
 */
final class ReturnFromLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequest $request, CarbonInterface $backAt): LeaveRequest
    {
        Gate::forUser($actor)->authorize('returnEarly', $request);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $request, $backAt): LeaveRequest {
            $employee = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->with('leaveType')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveStatus::Approved) {
                throw ValidationException::withMessages(['back_at' => 'Only approved leave can be shortened.']);
            }

            $back = $backAt->toDateString();

            if ($back <= $request->start_date->toDateString() || $back > $request->end_date->toDateString()) {
                throw ValidationException::withMessages(['back_at' => 'The return date must fall after the first day and on or before the last day of the leave. To drop the whole request, cancel it.']);
            }

            $country = $employee->country;

            if ($country === null) {
                throw ValidationException::withMessages(['back_at' => 'This employee has no country, so working days and holidays are unknown.']);
            }

            $kept = new LeaveSpan($request->start_date, CarbonImmutable::parse($back)->subDay(), $request->start_day_part, DayPart::Full);
            $keptTenths = $kept->chargedTenths($country);

            if ($keptTenths <= 0) {
                throw ValidationException::withMessages(['back_at' => 'No working day would be left on this leave. Cancel it instead.']);
            }

            $returnedTenths = Days::tenths((string) $request->days_requested) - $keptTenths;

            $request->update([
                'end_date' => $kept->end->toDateString(),
                'end_day_part' => DayPart::Full,
                'days_requested' => Days::format($keptTenths),
            ]);

            if ($returnedTenths > 0 && $request->leaveType->affects_balance) {
                LeaveLedgerEntry::query()->create([
                    'user_id' => $request->user_id,
                    'leave_type_id' => $request->leave_type_id,
                    'entry_type' => LedgerEntryType::Reversal,
                    'days' => Days::format($returnedTenths),
                    'effective_on' => WorkCalendar::today($employee)->toDateString(),
                    'leave_request_id' => $request->getKey(),
                    'note' => 'Back at work on '.$back,
                    'created_by' => $actor->getKey(),
                ]);
            }

            return $request->refresh();
        }));
    }
}
