<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use Carbon\CarbonInterface;

/** Input for one performance review (W-09 step 1). */
final readonly class PerformanceData
{
    public function __construct(
        public int $score,
        public CarbonInterface $reviewDate,
        public ?string $notes = null,
    ) {}
}
