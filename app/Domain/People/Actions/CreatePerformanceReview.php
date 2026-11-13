<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\PerformanceData;
use App\Domain\People\Models\Performance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * W-09 step 1: superadmin and admin (performances.create) review an employee: score 1 to 10, notes and a review
 * date. Who may read it back is a separate rule (BR-27, PerformancePolicy).
 */
class CreatePerformanceReview
{
    public function handle(User $actor, User $employee, PerformanceData $data): Performance
    {
        Gate::forUser($actor)->authorize('create', Performance::class);

        if ($data->score < 1 || $data->score > 10) {
            throw ValidationException::withMessages(['score' => 'The score must be between 1 and 10.']);
        }

        if ($employee->anonymized_at !== null) {
            throw ValidationException::withMessages(['user_id' => 'This employee has been anonymised.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): Performance => Performance::create([
            'user_id' => $employee->getKey(),
            'created_by' => $actor->getKey(),
            'score' => $data->score,
            'notes' => filled($data->notes) ? $data->notes : null,
            'review_date' => $data->reviewDate->toDateString(),
        ]));
    }
}
