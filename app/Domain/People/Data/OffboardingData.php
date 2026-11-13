<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\Enums\OffboardingStatus;
use Carbon\CarbonInterface;

/** Input for an offboarding (W-10 step 1). */
final readonly class OffboardingData
{
    public function __construct(
        public CarbonInterface $endDate,
        public bool $equipmentReturned = false,
        public bool $resignationLetterSigned = false,
        public ?string $description = null,
        public OffboardingStatus $status = OffboardingStatus::Pending,
    ) {}
}
