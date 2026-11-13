<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Data\FixedCostData;
use App\Domain\Clients\Models\Project;
use Illuminate\Validation\ValidationException;

trait ManagesFixedCosts
{
    /** @return array{name: string, date: string, amount_minor: int, currency_code: string} */
    private function fixedCostAttributes(Project $project, FixedCostData $data): array
    {
        // BR-14: a fixed cost uses the client's currency and counts toward the budget.
        if ($data->amount->currency !== $project->customer->currency_code) {
            throw ValidationException::withMessages(['currency' => "A fixed cost must be in the client's currency ({$project->customer->currency_code})."]);
        }

        if ($data->amount->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than zero.']);
        }

        return [
            'name' => $data->name,
            'date' => $data->date->copy()->startOfMonth()->toDateString(), // stored as the first day of the month
            'amount_minor' => $data->amount->minor,
            'currency_code' => $data->amount->currency,
        ];
    }
}
