<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Shared\Money;

/**
 * One project on the Financials page (D-91). The employee cost is null when a salary used for the project is in
 * another currency than the client's (BR-12: no conversion), and then costs and profit are null too.
 */
final readonly class FinancialsRow
{
    public function __construct(
        public int $projectId,
        public Money $budget,
        public Money $fixedCosts,
        public ?Money $employeeCosts,
        public int $loggedMinutes,
    ) {}

    /** Employee costs plus fixed costs. */
    public function costs(): ?Money
    {
        return $this->employeeCosts?->add($this->fixedCosts);
    }

    /** The budget already holds the fixed costs (D-04), so only the employee costs come off it. */
    public function profit(): ?Money
    {
        return $this->employeeCosts instanceof Money ? $this->budget->subtract($this->employeeCosts) : null;
    }
}
