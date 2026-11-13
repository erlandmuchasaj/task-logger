<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Appends a salary row (BR-29). Rows are never edited or deleted; the ledger settles statuses. */
class RecordSalary
{
    public function __construct(private readonly SalaryLedger $ledger) {}

    public function handle(User $actor, User $employee, SalaryData $data): Salary
    {
        Gate::forUser($actor)->authorize('manageSalaries', $employee);

        if ($data->amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The salary must be greater than zero.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): Salary => DB::transaction(function () use ($actor, $employee, $data): Salary {
            // Serialise concurrent writes for one employee so statuses settle consistently.
            User::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $salary = Salary::create([
                'user_id' => $employee->getKey(),
                'created_by' => $actor->getKey(),
                'amount_minor' => $data->amount->minor,
                'currency_code' => $data->amount->currency,
                'effective_date' => $data->effectiveDate->toDateString(),
                'status' => SalaryStatus::Pending,
                'description' => $data->description,
            ]);

            $this->ledger->settle($employee->getKey());

            return $salary->refresh();
        }));
    }
}
