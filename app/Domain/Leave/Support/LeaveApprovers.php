<?php

declare(strict_types=1);

namespace App\Domain\Leave\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestApproval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * D-70: who decides, and who is told, about a leave request. The approver is the requester's manager (an HR
 * user's too); when there is none, or the manager is deactivated or anonymised, the superadmins. HR means the
 * holders of leave_requests.approve who are not root. Nobody is ever told about their own request.
 */
final class LeaveApprovers
{
    /** The manager, when there is one who can still be told (not deactivated, not anonymised). */
    public static function managerOf(User $employee): ?User
    {
        return $employee->manager_id === null ? null : self::reachable()->whereKey($employee->manager_id)->first();
    }

    /**
     * D-70: an HR user's leave has no HR stage, because HR cannot approve their own and their manager's decision is final.
     * It holds only while they have a manager to decide; without one the HR stage stays, so somebody can still decide.
     */
    public static function skipsHrStage(User $employee): bool
    {
        return $employee->manager_id !== null && ! $employee->holdsRootAccess() && $employee->can(Permissions::LEAVE_REQUESTS_APPROVE);
    }

    /**
     * Who is told when a request is made: the approver, and HR too unless the requester is HR (nobody else has a stage to decide).
     *
     * @return Collection<int, User>
     */
    public static function submissionRecipients(User $employee): Collection
    {
        return self::skipsHrStage($employee) ? self::approversOf($employee) : self::approversAndHrOf($employee);
    }

    /**
     * @return Collection<int, User>
     */
    public static function approversOf(User $employee): Collection
    {
        $manager = self::managerOf($employee);

        return $manager instanceof User
            ? new Collection([$manager])
            : self::superadmins($employee);
    }

    /**
     * @return Collection<int, User>
     */
    public static function hrOf(User $employee): Collection
    {
        return self::reachable()
            ->permission(Permissions::LEAVE_REQUESTS_APPROVE)
            ->whereKeyNot($employee->getKey())
            ->get()
            ->reject(fn (User $user): bool => $user->holdsRootAccess())
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public static function superadmins(User $employee): Collection
    {
        return self::reachable()
            ->permission(Permissions::SYSTEM_ROOT)
            ->whereKeyNot($employee->getKey())
            ->get();
    }

    /**
     * D-87: who is reminded about a pending request: whoever still has a stage to decide. The approver when the manager
     * stage is open, HR when the HR stage is open. With no manager, or when nothing is open, HR and the superadmins.
     *
     * @return Collection<int, User>
     */
    public static function reminderRecipients(LeaveRequest $request): Collection
    {
        $employee = $request->user;
        $managerOpen = $employee->manager_id !== null && ! $request->stageDecision(ApprovalStage::Manager) instanceof LeaveRequestApproval;
        $hrOpen = ! self::skipsHrStage($employee) && ! $request->stageDecision(ApprovalStage::Hr) instanceof LeaveRequestApproval;

        if ($employee->manager_id === null || (! $managerOpen && ! $hrOpen)) {
            return self::hrAndSuperadminsOf($employee);
        }

        $recipients = new Collection;

        if ($managerOpen) {
            $recipients = $recipients->merge(self::approversOf($employee));
        }

        if ($hrOpen) {
            $recipients = $recipients->merge(self::hrOf($employee));
        }

        return $recipients->unique(fn (User $user): int => (int) $user->getKey())->values();
    }

    /**
     * Approver and HR together, each person once.
     *
     * @return Collection<int, User>
     */
    public static function approversAndHrOf(User $employee): Collection
    {
        return self::approversOf($employee)->merge(self::hrOf($employee))->unique(fn (User $user): int => (int) $user->getKey())->values();
    }

    /**
     * HR and the superadmins together, each person once: who is told when a request is still pending on its first day.
     *
     * @return Collection<int, User>
     */
    public static function hrAndSuperadminsOf(User $employee): Collection
    {
        return self::hrOf($employee)->merge(self::superadmins($employee))->unique(fn (User $user): int => (int) $user->getKey())->values();
    }

    /** @return Builder<User> */
    private static function reachable(): Builder
    {
        return User::query()->whereNull('banned_at')->whereNull('anonymized_at');
    }
}
