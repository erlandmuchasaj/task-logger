<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Removes a rate row. Refused if a project's budget would lose the rate it needs (rolled back). */
final readonly class DeleteClientRate
{
    public function __construct(private RecomputeCustomerBudgets $recompute) {}

    public function handle(User $actor, ClientTechnologyRate $rate): void
    {
        $customer = $rate->customer;
        Gate::forUser($actor)->authorize('manageRates', $customer);

        resolve(CauserResolver::class)->withCauser($actor, fn () => DB::transaction(function () use ($rate, $customer): void {
            $rate->delete();
            $this->recompute->handle($customer);
        }));
    }
}
