<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Shared\Money;

/** A project with its technology lines and totals. */
final readonly class EstimateProject
{
    /** @param  list<EstimateLine>  $lines */
    public function __construct(
        public int $projectId,
        public string $project,
        public string $customer,
        public string $status,
        public array $lines,
        public ?Money $value,
    ) {}

    public function estimatedHundredths(): int
    {
        return array_sum(array_map(fn (EstimateLine $line): int => $line->estimatedHundredths, $this->lines));
    }

    public function loggedMinutes(): int
    {
        return array_sum(array_map(fn (EstimateLine $line): int => $line->loggedMinutes, $this->lines));
    }

    public function total(): EstimateLine
    {
        return new EstimateLine(0, 'Total', $this->estimatedHundredths(), $this->loggedMinutes(), $this->value);
    }
}
