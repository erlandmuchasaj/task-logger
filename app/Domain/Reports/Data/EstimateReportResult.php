<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Shared\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** One page of projects plus the grand totals of everything the filters matched. `values` is empty without clients.view_rates. */
final readonly class EstimateReportResult
{
    /**
     * @param  LengthAwarePaginator<int, EstimateProject>  $projects
     * @param  array<string, Money>  $values  by currency
     */
    public function __construct(
        public LengthAwarePaginator $projects,
        public int $estimatedHundredths,
        public int $loggedMinutes,
        public array $values,
    ) {}
}
