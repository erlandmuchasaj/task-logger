<?php

declare(strict_types=1);

namespace App\Domain\Leave\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Models\LeaveRequest;

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
     * D-44 (confirmed by the owner): people cancel the requests they made themselves; HR (leave_requests.approve)
     * and the superadmin (leave_requests.create_for_others) cancel any, including an approved one or a direct day off.
     */
    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_CREATE_FOR_OTHERS)
            || $user->can(Permissions::LEAVE_REQUESTS_APPROVE)
            || ($request->created_by === $user->getKey() && $request->user_id === $user->getKey() && $user->can(Permissions::LEAVE_REQUESTS_CREATE_OWN));
    }

    private function isManagerOf(User $user, LeaveRequest $request): bool
    {
        return $user->can(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS)
            && ($request->relationLoaded('user') ? $request->user->manager_id : $request->user()->value('manager_id')) === $user->getKey();
    }
}
