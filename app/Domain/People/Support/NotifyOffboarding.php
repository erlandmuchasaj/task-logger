<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Support\LeaveApprovers;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Notifications\OffboardingStarted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-75: when an offboarding starts, tell the person's manager and HR, at once and after the transaction commits (never a
 * deactivated or anonymised person). HR is the same group as in D-70. With no manager to tell (the person is a
 * superadmin, or the manager is deactivated) the superadmins are told in their place.
 */
final class NotifyOffboarding
{
    public static function send(Offboarding $offboarding, User $employee): void
    {
        $recipients = self::recipients($employee);

        DB::afterCommit(function () use ($offboarding, $recipients): void {
            foreach ($recipients as $recipient) {
                $recipient->notify(new OffboardingStarted($offboarding));
            }
        });
    }

    /** @return Collection<int, User> */
    public static function recipients(User $employee): Collection
    {
        $manager = LeaveApprovers::managerOf($employee);
        $first = $manager instanceof User ? collect([$manager]) : LeaveApprovers::superadmins($employee);

        return $first->merge(LeaveApprovers::hrOf($employee))->unique(fn (User $user): int => (int) $user->getKey())->values();
    }
}
