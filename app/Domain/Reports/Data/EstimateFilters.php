<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Clients\Enums\ProjectStatus;
use Carbon\CarbonInterface;

/** What to include in the estimated-versus-logged report. The dates limit the logs counted, not the estimates. */
final readonly class EstimateFilters
{
    public function __construct(
        public ?int $customerId = null,
        public ?int $projectId = null,
        public ?ProjectStatus $status = null,
        public ?CarbonInterface $from = null,
        public ?CarbonInterface $to = null,
    ) {}
}
