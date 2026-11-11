<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/** Adds an hourly rate for a client and technology from a date (rate history, BR-06). */
class RecordClientRate
{
    public function __construct(private readonly RecomputeCustomerBudgets $recompute) {}

    public function handle(User $actor, Customer $customer, int $technologyId, int $rateMinor, CarbonInterface $effectiveFrom): ClientTechnologyRate
    {
        Gate::forUser($actor)->authorize('manageRates', $customer);

        if ($rateMinor <= 0) {
            throw ValidationException::withMessages(['rate' => 'The rate must be greater than zero.']);
        }

        $exists = ClientTechnologyRate::query()->where('customer_id', $customer->getKey())
            ->where('technology_id', $technologyId)->whereDate('effective_from', $effectiveFrom->toDateString())->exists();
        if ($exists) {
            throw ValidationException::withMessages(['effective_from' => 'This technology already has a rate from that date.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): ClientTechnologyRate => DB::transaction(function () use ($customer, $technologyId, $rateMinor, $effectiveFrom): ClientTechnologyRate {
            $rate = ClientTechnologyRate::create([
                'customer_id' => $customer->getKey(),
                'technology_id' => $technologyId,
                'rate_minor' => $rateMinor,
                'effective_from' => $effectiveFrom->toDateString(),
            ]);

            $this->recompute->handle($customer);

            return $rate;
        }));
    }
}
