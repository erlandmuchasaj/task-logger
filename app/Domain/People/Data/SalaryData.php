<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\Shared\Money;
use Carbon\CarbonInterface;

final readonly class SalaryData
{
    public function __construct(
        public Money $amount,
        public CarbonInterface $effectiveDate,
        public ?string $description = null,
    ) {}
}
