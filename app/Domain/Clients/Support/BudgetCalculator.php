<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use App\Domain\Clients\Models\ClientTechnologyRate;
use App\Domain\Clients\Models\Project;
use App\Domain\Shared\Money;

/**
 * D-04, BR-13, BR-14. Budget = sum over technologies of (estimated hours x the client rate effective
 * on the project start date) plus the sum of the project's fixed costs, in the client's currency.
 * Integer arithmetic only. Each technology line rounds half up to the minor unit [ASSUMED].
 */
final class BudgetCalculator
{
    public function calculate(Project $project): ProjectBudget
    {
        $customer = $project->customer;
        $currency = $customer->currency_code;

        $hundredths = 0;
        $minor = 0;
        $missing = [];

        foreach ($project->technologyLines()->get() as $line) {
            $lineHundredths = (int) str_replace('.', '', number_format((float) $line->estimated_hours, 2, '.', ''));
            $hundredths += $lineHundredths;

            $rate = ClientRates::forDate($customer->getKey(), $line->technology_id, $project->start_date);

            if (! $rate instanceof ClientTechnologyRate) {
                $missing[] = $line->technology_id;

                continue;
            }

            $minor += intdiv($rate->rate_minor * $lineHundredths + 50, 100); // half up
        }

        foreach ($project->fixedCosts()->get() as $cost) {
            $minor += $cost->amount_minor; // same currency is enforced when a fixed cost is saved (BR-14)
        }

        return new ProjectBudget($hundredths, Money::of($minor, $currency), $missing);
    }
}
