<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Models\Salary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * D-83: fixes the amount, currency, date or note of a salary row entered by mistake. The row stays in the
 * timeline (it is never deleted) and the audit entry keeps the old and new values. The ledger settles
 * the statuses again, because a new date can change which row is active or pending.
 */
final readonly class CorrectSalary
{
    use ValidatesSalary;

    public function __construct(private SalaryLedger $ledger) {}

    public function handle(User $actor, Salary $salary, SalaryData $data): Salary
    {
        Gate::forUser($actor)->authorize('update', $salary);

        $this->validateSalary($data);

        return resolve(CauserResolver::class)->withCauser($actor, fn (): Salary => DB::transaction(function () use ($salary, $data): Salary {
            // Serialise concurrent writes for one employee so statuses settle consistently.
            User::query()->whereKey($salary->user_id)->lockForUpdate()->first();

            $salary->applyCorrection([
                'amount_minor' => $data->amount->minor,
                'currency_code' => $data->amount->currency,
                'effective_date' => $data->effectiveDate->toDateString(),
                'description' => $data->description,
            ]);

            $this->ledger->settle($salary->user_id);

            return $salary->refresh();
        }));
    }
}
