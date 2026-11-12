<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
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
}
