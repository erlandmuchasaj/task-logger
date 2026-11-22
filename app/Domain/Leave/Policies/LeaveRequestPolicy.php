<?php

declare(strict_types=1);

namespace App\Domain\Leave\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Work\Support\WorkCalendar;

/**
 * Permission-first (SPEC 4.1). Everyone with leave_requests.view_own sees their own requests; view_any
 * (superadmin, hr) sees all; a direct manager sees their reports' requests through the relationship, so the
 * manager stage can work (D-28). Approval stages follow D-07; "not your own request" (BR-20) is enforced in
 * DecideLeave so root access cannot skip it.
 */
final class LeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_VIEW_OWN)
            || $user->can(Permissions::LEAVE_REQUESTS_VIEW_ANY)
            || $user->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS);
    }

    public function view(User $user, LeaveRequest $request): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_VIEW_ANY)
            || ($user->can(Permissions::LEAVE_REQUESTS_VIEW_OWN) && $request->user_id === $user->getKey())
            || $this->isManagerOf($user, $request);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_CREATE_OWN);
    }

    public function createForOthers(User $user): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_CREATE_FOR_OTHERS);
    }

    public function approveManagerStage(User $user, LeaveRequest $request): bool
    {
        return $this->isManagerOf($user, $request);
    }

    public function approveHrStage(User $user, LeaveRequest $request): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_APPROVE);
    }

    /**
     * D-44, D-71 (the owner's rules of 2026-11-22). "Taken" means today has reached the first day, in the employee's
     * calendar. Up to then the requester (for a request they made themselves), HR and the superadmin cancel a pending
     * or an approved request; from then on only the superadmin may cancel an approved one, and nobody a pending one.
     * `cancel` stays under this policy for root (RootAccess::POLICY_ONLY), because a pending request that has started
     * is not the superadmin's to cancel either: it is decided or rejected.
     */
    public function cancel(User $user, LeaveRequest $request): bool
    {
        $started = $this->hasStarted($request);

        if ($user->holdsRootAccess()) {
            return ! $started || $request->status !== LeaveStatus::Pending;
        }

        $mayCancel = $user->can(Permissions::LEAVE_REQUESTS_APPROVE)
            || ($request->created_by === $user->getKey() && $request->user_id === $user->getKey() && $user->can(Permissions::LEAVE_REQUESTS_CREATE_OWN));

        return $mayCancel && ! $started;
    }

    /** D-73: shortening approved leave, once the employee is back, is HR's and the superadmin's call, never for one's own leave. The action checks the status. */
    public function returnEarly(User $user, LeaveRequest $request): bool
    {
        return $request->user_id !== $user->getKey()
            && ($user->can(Permissions::LEAVE_REQUESTS_APPROVE) || $user->can(Permissions::LEAVE_REQUESTS_CREATE_FOR_OTHERS));
    }

    /** Pending or approved leave whose first day has come: the action itself explains why any other status cannot be cancelled. */
    private function hasStarted(LeaveRequest $request): bool
    {
        return in_array($request->status, [LeaveStatus::Pending, LeaveStatus::Approved], true)
            && $request->hasStarted(WorkCalendar::today($request->relationLoaded('user') ? $request->user : $request->user()->firstOrFail()));
    }

    private function isManagerOf(User $user, LeaveRequest $request): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS)
            && ($request->relationLoaded('user') ? $request->user->manager_id : $request->user()->value('manager_id')) === $user->getKey();
    }
}
