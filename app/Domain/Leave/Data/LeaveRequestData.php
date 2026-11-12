<?php

declare(strict_types=1);

namespace App\Domain\Leave\Data;

use App\Domain\Leave\Enums\DayPart;
use Carbon\CarbonInterface;

/** Input for one leave request or direct day off. Days are never part of the input (BR-16). */
final readonly class LeaveRequestData
{
    public function __construct(
        public int $leaveTypeId,
        public CarbonInterface $startDate,
        public CarbonInterface $endDate,
        public DayPart $startDayPart = DayPart::Full,
        public DayPart $endDayPart = DayPart::Full,
        public ?string $reason = null,
    ) {}
}
