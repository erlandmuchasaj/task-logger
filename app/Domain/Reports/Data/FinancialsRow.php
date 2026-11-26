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

    /** The budget already holds the fixed costs (D-04), so only the employee costs come off it. A loss is negative. */
    public function profitMinor(): ?int
    {
        return $this->employeeCosts instanceof Money ? $this->budget->minor - $this->employeeCosts->minor : null;
    }

    /** "EUR -12.50" for a loss. Money is unsigned, so the sign is added here. */
    public function profitLabel(): ?string
    {
        return $this->signed(fn (Money $amount): string => $amount->format());
    }

    /** "-12.50" for a loss, for exports. */
    public function profitMajor(): ?string
    {
        return $this->signed(fn (Money $amount): string => $amount->toMajorString());
    }

    public function hours(): string
    {
        return intdiv($this->loggedMinutes, 60).':'.mb_str_pad((string) ($this->loggedMinutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** @param callable(Money): string $format */
    private function signed(callable $format): ?string
    {
        $minor = $this->profitMinor();

        if ($minor === null) {
            return null;
        }

        return ($minor < 0 ? '-' : '').$format(Money::of(abs($minor), $this->budget->currency));
    }
}
