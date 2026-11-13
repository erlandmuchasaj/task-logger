<?php

declare(strict_types=1);

namespace App\Domain\Clients\Support;

use App\Domain\Shared\Money;

/**
 * Result of the budget calculation (D-04). `estimatedHoursHundredths` is in hundredths of an hour so
 * everything stays integer. `missingTechnologyIds` lists technologies without a rate on the start date.
 */
final readonly class ProjectBudget
{
    /** @param  list<int>  $missingTechnologyIds */
    public function __construct(
        public int $estimatedHoursHundredths,
        public Money $budget,
        public array $missingTechnologyIds = [],
    ) {}

    public function estimatedHours(): string
    {
        return intdiv($this->estimatedHoursHundredths, 100).'.'.str_pad((string) ($this->estimatedHoursHundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
