<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Customer;

/** Rates changed: every project of the client gets its budget snapshot recomputed (D-04). */
class RecomputeCustomerBudgets
{
    public function __construct(private readonly ComputeProjectBudget $compute) {}

    public function handle(Customer $customer): void
    {
        $customer->projects()->get()->each(function ($project) use ($customer): void {
            $project->setRelation('customer', $customer);
            $this->compute->handle($project);
        });
    }
}
