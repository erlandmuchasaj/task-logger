<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-06 / BR-22: the superadmin records a day off for someone who did not come to work. It skips
 * approval, is stored as approved and deducts PTO or sick leave straight away. A note is required;
 * with `overrideBalance` it may take the balance below zero (BR-24).
 */
class CreateDirectDayOff
{
    use ValidatesLeave;

    public function handle(User $actor, User $employee, LeaveRequestData $data, bool $overrideBalance = false): LeaveRequest
    {
        Gate::forUser($actor)->authorize('createForOthers', LeaveRequest::class);

        if (trim((string) $data->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A note is required for a direct day off.']);
        }

        $prepared = $this->prepare($employee, $data);

        if (! $prepared['type']->affects_balance) {
            throw ValidationException::withMessages(['leave_type_id' => 'A direct day off is deducted from PTO or sick leave.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $employee, $data, $overrideBalance): LeaveRequest {
            $this->lockEmployee($employee);

            ['span' => $span, 'tenths' => $tenths, 'type' => $type] = $this->prepare($employee, $data);
            $this->assertNoOverlap($employee, $span);

            if (! $overrideBalance) {
                $this->assertBalance($employee, $type, $tenths);
            }

            $request = LeaveRequest::create([
                'user_id' => $employee->getKey(),
                'created_by' => $actor->getKey(),
                'leave_type_id' => $type->getKey(),
                'start_date' => $span->start->toDateString(),
                'end_date' => $span->end->toDateString(),
                'start_day_part' => $span->startPart,
                'end_day_part' => $span->endPart,
                'days_requested' => Days::format($tenths),
                'reason' => $data->reason,
                'status' => LeaveStatus::Approved,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ]);

            LeaveLedgerEntry::create([
                'user_id' => $employee->getKey(),
                'leave_type_id' => $type->getKey(),
                'entry_type' => LedgerEntryType::Deduction,
                'days' => Days::format(-$tenths),
                'effective_on' => $span->start->toDateString(),
                'leave_request_id' => $request->getKey(),
                'note' => $overrideBalance ? 'Direct day off (balance override): '.$data->reason : 'Direct day off: '.$data->reason,
                'created_by' => $actor->getKey(),
            ]);

            return $request;
        }));
    }
}
