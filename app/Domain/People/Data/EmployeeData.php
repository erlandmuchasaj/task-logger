<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use Carbon\CarbonInterface;

/** Account-level employee data (W-01 step 2). Skills map technology id to level 1-10. */
final readonly class EmployeeData
{
    /** @param  array<int, int>  $skills  technology_id => level */
    public function __construct(
        public string $name,
        public ?string $surname,
        public string $email,
        public RoleName $role,
        public ?int $countryId,
        public ?int $departmentId,
        public ?string $position,
        public ?int $managerId,
        public ?EmploymentType $employmentType,
        public ?CarbonInterface $startDate,
        public ?string $avatarPath = null,
        public array $skills = [],
    ) {}
}
