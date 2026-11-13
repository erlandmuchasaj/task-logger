<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-10 step 1: end date, equipment returned, resignation letter signed, description, status. At most one
 * non-cancelled offboarding per user; the database enforces it too. Creating one already `completed` deactivates
 * the login like completing it later does (ADR-0008).
 */
final class CreateOffboarding
{
    use ValidatesOffboarding;

    public function handle(User $actor, User $employee, OffboardingData $data): Offboarding
    {
        Gate::forUser($actor)->authorize('create', Offboarding::class);

        if ($data->status === OffboardingStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'An offboarding cannot start cancelled.']);
        }

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Offboarding => DB::transaction(function () use ($actor, $employee, $data): Offboarding {
            User::query()->whereKey($employee->getKey())->lockForUpdate()->first();
            $this->assertCanOffboard($employee);

            try {
                $offboarding = Offboarding::query()->create([
                    'user_id' => $employee->getKey(),
                    'created_by' => $actor->getKey(),
                    'end_date' => $data->endDate->toDateString(),
                    'equipment_returned' => $data->equipmentReturned,
                    'resignation_letter_signed' => $data->resignationLetterSigned,
                    'description' => filled($data->description) ? $data->description : null,
                    'status' => $data->status,
                ]);
            } catch (QueryException) {
                throw ValidationException::withMessages(['user_id' => 'This employee already has an offboarding that is not cancelled.']);
            }

            if ($data->status === OffboardingStatus::Completed) {
                resolve(ChangeOffboardingStatus::class)->deactivate($actor, $offboarding);
            }

            return $offboarding;
        }));
    }
}
