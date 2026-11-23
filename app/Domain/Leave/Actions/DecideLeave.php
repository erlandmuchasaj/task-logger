<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestApproval;
use App\Domain\Leave\Support\LeaveApprovers;
use App\Domain\Leave\Support\NotifyLeaveDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-05 steps 2 to 4 (D-07, D-85): the direct manager and HR decide their own stages in any order. The request is
 * approved when every required stage has approved (the manager stage is skipped when the employee has no manager,
 * the HR stage for an HR user's own leave); whoever approves last completes it. Any rejection, at either stage, ends
 * it at once and needs a note (BR-21). Nobody decides their own request, root included (BR-20). A direct manager who
 * also holds the HR stage (HR or root) decides both with one approval (D-86).
 * Final approval writes the deduction to the ledger.
 */
final class DecideLeave
{
    use ValidatesLeave;

    public function handle(User $actor, LeaveRequest $request, ApprovalStage $stage, ApprovalDecision $decision, ?string $note = null): LeaveRequest
    {
        Gate::forUser($actor)->authorize($stage === ApprovalStage::Manager ? 'approveManagerStage' : 'approveHrStage', $request);

        $note = filled($note) ? mb_trim($note) : null;

        if ($decision === ApprovalDecision::Rejected && $note === null) {
            throw ValidationException::withMessages(['note' => 'A rejection needs a note.']);
        }

        return resolve(CauserResolver::class)->withCauser($actor, fn (): LeaveRequest => DB::transaction(function () use ($actor, $request, $stage, $decision, $note): LeaveRequest {
            $employee = User::query()->whereKey($request->user_id)->lockForUpdate()->firstOrFail();
            $request = LeaveRequest::query()->with(['approvals', 'leaveType'])->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

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

            if ($stage === ApprovalStage::Hr && LeaveApprovers::skipsHrStage($employee)) {
                throw ValidationException::withMessages(['stage' => "An HR user's leave has no HR stage: their manager decides it."]);
            }

            if ($request->stageDecision($stage) !== null) {
                throw ValidationException::withMessages(['stage' => 'This stage has already been decided.']);
            }

            LeaveRequestApproval::query()->create([
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

            // D-86: a direct manager who also holds the HR stage (HR or root) is both stages, so one approval covers both.
            if ($employee->manager_id === $actor->getKey() && Gate::forUser($actor)->allows('approveHrStage', $request)) {
                $this->approveOtherStageToo($request, $employee, $actor, $stage, $note);
            }

            // D-85: the other stage, when it is required, may still be open, in either direction.
            $otherStage = $stage === ApprovalStage::Manager ? ApprovalStage::Hr : ApprovalStage::Manager;
            $otherRequired = $stage === ApprovalStage::Manager ? ! LeaveApprovers::skipsHrStage($employee) : $hasManager;

            if ($otherRequired && $request->refresh()->stageDecision($otherStage)?->decision !== ApprovalDecision::Approved) {
                return $request->refresh();
            }

            return $this->approve($request, $employee, $actor, $note);
        }));
    }

    private function approveOtherStageToo(LeaveRequest $request, User $employee, User $actor, ApprovalStage $stage, ?string $note): void
    {
        $other = $stage === ApprovalStage::Manager ? ApprovalStage::Hr : ApprovalStage::Manager;

        if ($other === ApprovalStage::Hr && LeaveApprovers::skipsHrStage($employee)) {
            return;
        }

        if ($request->refresh()->stageDecision($other) instanceof LeaveRequestApproval) {
            return;
        }

        LeaveRequestApproval::query()->create([
            'leave_request_id' => $request->getKey(),
            'stage' => $other,
            'reviewer_id' => $actor->getKey(),
            'decision' => ApprovalDecision::Approved,
            'decided_at' => now(),
            'note' => $note,
        ]);
    }

    private function approve(LeaveRequest $request, User $employee, User $actor, ?string $note): LeaveRequest
    {
        $this->writeDeduction($request, $employee, $request->leaveType, $actor);

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

        $request->refresh();

        NotifyLeaveDecision::send($actor, $request);

        return $request;
    }
}
