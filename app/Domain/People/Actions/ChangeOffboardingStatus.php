<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use App\Domain\People\Support\Deactivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-10 step 2 (D-45): pending moves to in progress, completed or cancelled; in progress to completed or cancelled;
 * completed and cancelled are final. Completing deactivates the login as a system effect of offboardings.manage,
 * not of employees.deactivate (ADR-0008); it is refused for the last superadmin and for yourself (D-32).
 */
final class ChangeOffboardingStatus
{
    public function handle(User $actor, Offboarding $offboarding, OffboardingStatus $to): Offboarding
    {
        Gate::forUser($actor)->authorize('changeStatus', $offboarding);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Offboarding => DB::transaction(function () use ($actor, $offboarding, $to): Offboarding {
            $offboarding = Offboarding::query()->with('employee')->whereKey($offboarding->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($to, $offboarding->status->next(), true)) {
                throw ValidationException::withMessages(['status' => 'An offboarding cannot move from '.$offboarding->status->getLabel().' to '.$to->getLabel().'.']);
            }

            if ($to === OffboardingStatus::Completed) {
                $this->deactivate($actor, $offboarding);
            }

            $offboarding->update(['status' => $to]);

            return $offboarding;
        }));
    }

    /** The side effect of completion (ADR-0008). Public so CreateOffboarding can complete at creation. */
    public function deactivate(User $actor, Offboarding $offboarding): void
    {
        Deactivation::ban($actor, $offboarding->employee ?? User::query()->whereKey($offboarding->user_id)->firstOrFail(), 'Offboarding completed', ['offboarding_id' => $offboarding->getKey()]);
    }
}
