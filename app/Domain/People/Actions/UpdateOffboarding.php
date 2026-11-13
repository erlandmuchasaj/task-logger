<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\OffboardingData;
use App\Domain\People\Models\Offboarding;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Changes the facts of an offboarding (end date, equipment, letter, description) while it is still open. Status moves through ChangeOffboardingStatus. */
final class UpdateOffboarding
{
    public function handle(User $actor, Offboarding $offboarding, OffboardingData $data): Offboarding
    {
        Gate::forUser($actor)->authorize('update', $offboarding);

        // A screen may hold an old copy: decide on what the database says now.
        if ($offboarding->refresh()->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'A completed or cancelled offboarding can no longer be changed.']);
        }

        return resolve(CauserResolver::class)->withCauser($actor, function () use ($offboarding, $data): Offboarding {
            $offboarding->update([
                'end_date' => $data->endDate->toDateString(),
                'equipment_returned' => $data->equipmentReturned,
                'resignation_letter_signed' => $data->resignationLetterSigned,
                'description' => filled($data->description) ? $data->description : null,
            ]);

            return $offboarding;
        });
    }
}
