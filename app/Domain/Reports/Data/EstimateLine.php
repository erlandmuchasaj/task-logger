<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Shared\Money;

/**
 * One technology of one project: estimated hours against logged time. Hours are in hundredths so no float is
 * involved. `value` is the logged time priced at the rate snapshot of each log (BR-06); it is null for people
 * without clients.view_rates (D-05).
 */
final readonly class EstimateLine
{
    public function __construct(
        public int $technologyId,
        public string $technology,
        public int $estimatedHundredths,
        public int $loggedMinutes,
        public ?Money $value,
    ) {}

    public function loggedHundredths(): int
    {
        return intdiv($this->loggedMinutes * 100 + 30, 60);   // half up
    }

    /** Positive: more time logged than estimated. */
    public function varianceHundredths(): int
    {
        return $this->loggedHundredths() - $this->estimatedHundredths;
    }

    /** Logged as a whole percentage of the estimate, or null when nothing was estimated. */
    public function percentUsed(): ?int
    {
        return $this->estimatedHundredths > 0 ? intdiv($this->loggedHundredths() * 100 + intdiv($this->estimatedHundredths, 2), $this->estimatedHundredths) : null;
    }

    /** "12.50" becomes 1250, without floats. */
    public static function hundredthsOf(string $decimal): int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal), 2, '0');

        return (int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    public static function hours(int $hundredths): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $hundredths = abs($hundredths);

        return $sign.intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }
}
