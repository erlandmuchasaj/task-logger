<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Data;

use Carbon\CarbonInterface;

/** One line as typed. The unit price is a decimal string in the expense's currency ("12.50"); the amount is never input (BR-12). */
final readonly class ExpenseLineData
{
    public function __construct(
        public string $description,
        public int $quantity,
        public string $unitPrice,
        public CarbonInterface $date,
    ) {}
}
