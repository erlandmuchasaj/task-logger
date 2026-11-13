<?php

declare(strict_types=1);

namespace App\Domain\Clients\Data;

use App\Domain\Shared\Money;
use Carbon\CarbonInterface;

final readonly class FixedCostData
{
    public function __construct(
        public string $name,
        public CarbonInterface $date,
        public Money $amount,
    ) {}
}
