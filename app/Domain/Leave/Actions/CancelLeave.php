<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Work\Support\WorkCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** BR-19: pending or approved (not yet taken) becomes cancelled; an approved one gives its days back through a reversal entry. */
class CancelLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequest $request): LeaveRequest
    {
        Gate::forUser($actor)->authorize('cancel', $request);

        return app(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $request): LeaveRequest {
            $employee = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->with('leaveType')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($request->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)) {
                throw ValidationException::withMessages(['request' => 'Only a pending or approved request can be cancelled.']);
            }

            $wasApproved = $request->status === LeaveStatus::Approved;
            $request->update(['status' => LeaveStatus::Cancelled]);

            if ($wasApproved && $request->leaveType->affects_balance) {
                LeaveLedgerEntry::create([
                    'user_id' => $request->user_id,
                    'leave_type_id' => $request->leave_type_id,
                    'entry_type' => LedgerEntryType::Reversal,
                    'days' => Days::format(Days::tenths((string) $request->days_requested)),
                    'effective_on' => WorkCalendar::today($employee)->toDateString(),
                    'leave_request_id' => $request->getKey(),
                    'note' => 'Cancelled leave',
                    'created_by' => $actor->getKey(),
                ]);
            }

            return $request->refresh();
        }));
    }
}
