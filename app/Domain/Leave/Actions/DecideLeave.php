<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestApproval;
use App\Domain\Leave\Support\Days;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-05 steps 2 to 4 (D-07): the direct manager decides first, then HR. The request is approved when every
 * required stage has approved (the manager stage is skipped when the employee has no manager); any
 * rejection ends it and needs a note (BR-21). Nobody decides their own request, root included (BR-20).
 * Final approval writes the deduction to the ledger.
 */
class DecideLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequest $request, ApprovalStage $stage, ApprovalDecision $decision, ?string $note = null): LeaveRequest
    {
        Gate::forUser($actor)->authorize($stage === ApprovalStage::Manager ? 'approveManagerStage' : 'approveHrStage', $request);

        $note = filled($note) ? trim((string) $note) : null;

        if ($decision === ApprovalDecision::Rejected && $note === null) {
            throw ValidationException::withMessages(['note' => 'A rejection needs a note.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $request, $stage, $decision, $note): LeaveRequest {
            $employee = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->with(['approvals', 'leaveType'])->lockForUpdate()->findOrFail($request->getKey());

            if ($request->user_id === $actor->getKey()) {
                throw ValidationException::withMessages(['request' => 'You cannot decide your own leave request.']);
            }

            if ($request->status !== LeaveStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only a pending request can be decided.']);
            }

            $hasManager = $employee->manager_id !== null;

            if ($stage === ApprovalStage::Manager && ! $hasManager) {
                throw ValidationException::withMessages(['stage' => 'This employee has no manager, so there is no manager stage.']);
            }

            if ($request->stageDecision($stage) !== null) {
                throw ValidationException::withMessages(['stage' => 'This stage has already been decided.']);
            }

            if ($stage === ApprovalStage::Hr && $hasManager && $request->stageDecision(ApprovalStage::Manager)?->decision !== ApprovalDecision::Approved) {
                throw ValidationException::withMessages(['stage' => 'The manager must decide before HR.']);
            }

            LeaveRequestApproval::create([
                'leave_request_id' => $request->getKey(),
                'stage' => $stage,
                'reviewer_id' => $actor->getKey(),
                'decision' => $decision,
                'decided_at' => now(),
                'note' => $note,
            ]);

            if ($decision === ApprovalDecision::Rejected) {
                return $this->finish($request, $actor, LeaveStatus::Rejected, $note);
            }

            // The manager stage never completes the request; HR is the last stage and the manager already approved (checked above).
            if ($stage === ApprovalStage::Manager) {
                return $request->refresh();
            }

            return $this->approve($request, $employee, $actor, $note);
        }));
    }

    private function approve(LeaveRequest $request, User $employee, User $actor, ?string $note): LeaveRequest
    {
        $type = $request->leaveType;
        $tenths = Days::tenths((string) $request->days_requested);

        if ($type->affects_balance) {
            // The request's own days are no longer "pending" once approved, so exclude it from the reservation.
            $this->assertBalance($employee, $type, $tenths, (int) $request->getKey());

            LeaveLedgerEntry::create([
                'user_id' => $request->user_id,
                'leave_type_id' => $request->leave_type_id,
                'entry_type' => LedgerEntryType::Deduction,
                'days' => Days::format(-$tenths),
                'effective_on' => $request->start_date->toDateString(),
                'leave_request_id' => $request->getKey(),
                'note' => 'Approved leave',
                'created_by' => $actor->getKey(),
            ]);
        }

        return $this->finish($request, $actor, LeaveStatus::Approved, $note);
    }

    private function finish(LeaveRequest $request, User $actor, LeaveStatus $status, ?string $note): LeaveRequest
    {
        $request->update([
            'status' => $status,
            'reviewed_by' => $actor->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $request->refresh();
    }
}
