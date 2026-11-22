<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Data\LeaveRequestData;
use App\Domain\Leave\Enums\LeaveAlert;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveApprovers;
use App\Domain\Leave\Support\NotifyLeaveApprovers;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-05 step 1: a person asks for leave for themselves. The server computes the days (BR-16). The manager and HR are
 * told at once (D-70). A superadmin has no manager, so there is nobody to ask: the request is approved as it is made.
 */
final class RequestLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequestData $data): LeaveRequest
    {
        Gate::forUser($actor)->authorize('create', LeaveRequest::class);

        $this->prepare($actor, $data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $data): LeaveRequest {
            $this->lockEmployee($actor);

            ['span' => $span, 'tenths' => $tenths, 'type' => $type] = $this->prepare($actor, $data);
            $this->assertNoOverlap($actor, $span);
            $this->assertBalance($actor, $type, $tenths);

            $selfApproves = $actor->manager_id === null && $actor->holdsRootAccess();

            $request = LeaveRequest::query()->create([
                'user_id' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'leave_type_id' => $type->getKey(),
                'start_date' => $span->start->toDateString(),
                'end_date' => $span->end->toDateString(),
                'start_day_part' => $span->startPart,
                'end_day_part' => $span->endPart,
                'days_requested' => Days::format($tenths),
                'reason' => $data->reason,
                'status' => $selfApproves ? LeaveStatus::Approved : LeaveStatus::Pending,
                'reviewed_by' => $selfApproves ? $actor->getKey() : null,
                'reviewed_at' => $selfApproves ? now() : null,
            ]);

            if ($selfApproves) {
                $this->deduct($request, $actor, $type, $tenths);

                return $request;
            }

            NotifyLeaveApprovers::send($request, LeaveAlert::Submitted, LeaveApprovers::submissionRecipients($actor));

            return $request;
        }));
    }

    private function deduct(LeaveRequest $request, User $employee, LeaveType $type, int $tenths): void
    {
        if (! $type->affects_balance) {
            return;
        }

        LeaveLedgerEntry::query()->create([
            'user_id' => $employee->getKey(),
            'leave_type_id' => $type->getKey(),
            'entry_type' => LedgerEntryType::Deduction,
            'days' => Days::format(-$tenths),
            'effective_on' => $request->start_date->toDateString(),
            'leave_request_id' => $request->getKey(),
            'note' => 'Approved leave (own request)',
            'created_by' => $employee->getKey(),
        ]);
    }
}
