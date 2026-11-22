<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Support\DirectReports;
use Illuminate\Validation\ValidationException;

trait ValidatesOffboarding
{
    /** @throws ValidationException */
    protected function assertCanOffboard(User $employee, ?int $exceptId = null): void
    {
        if ($employee->anonymized_at !== null) {
            throw ValidationException::withMessages(['user_id' => 'This employee has been anonymised.']);
        }

        $open = Offboarding::query()
            ->where('user_id', $employee->getKey())
            ->where('status', '!=', OffboardingStatus::Cancelled)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($open) {
            throw ValidationException::withMessages(['user_id' => 'This employee already has an offboarding that is not cancelled.']);
        }
    }

    /**
     * D-74: nobody leaves while others still report to them. Checked when an offboarding starts and again when it
     * completes, because a manager may have been given a new report in between.
     *
     * @throws ValidationException
     */
    protected function assertReportsReassigned(User $employee): void
    {
        $count = DirectReports::activeOf($employee)->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['direct_reports' => $employee->getFilamentName().' still has '.$count.($count === 1 ? ' direct report' : ' direct reports').'. Move them to another manager first.']);
        }
    }
}
