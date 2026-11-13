<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Changes a rate's amount or date. Logs keep their own snapshot (BR-06), so past work is unaffected;
 * project budgets are recomputed, and the change is refused if a project would lose its rate.
 */
class UpdateClientRate
{
    public function __construct(private readonly RecomputeCustomerBudgets $recompute) {}

    public function handle(User $actor, ClientTechnologyRate $rate, int $rateMinor, CarbonInterface $effectiveFrom): ClientTechnologyRate
    {
        $customer = $rate->customer;
        Gate::forUser($actor)->authorize('manageRates', $customer);

        if ($rateMinor <= 0) {
            throw ValidationException::withMessages(['rate' => 'The rate must be greater than zero.']);
        }

        $clash = ClientTechnologyRate::query()->where('customer_id', $customer->getKey())->where('technology_id', $rate->technology_id)
            ->whereDate('effective_from', $effectiveFrom->toDateString())->whereKeyNot($rate->getKey())->exists();
        if ($clash) {
            throw ValidationException::withMessages(['effective_from' => 'This technology already has a rate from that date.']);
        }

        return app(CauserResolver::class)->withCauser($actor, fn (): ClientTechnologyRate => DB::transaction(function () use ($rate, $customer, $rateMinor, $effectiveFrom): ClientTechnologyRate {
            $rate->update(['rate_minor' => $rateMinor, 'effective_from' => $effectiveFrom->toDateString()]);
            $this->recompute->handle($customer);

            return $rate->refresh();
        }));
    }
}
