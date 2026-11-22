<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use Carbon\CarbonInterface;
use SensitiveParameter;

/** D-77: what a person may change about themselves. Role, manager, salary and employment fields are not here. */
final readonly class OwnProfileData
{
    public function __construct(
        public string $name,
        public ?string $surname,
        public string $email,
        public ?string $phone = null,
        public ?CarbonInterface $dateOfBirth = null,
        public ?string $university = null,
        public ?string $address = null,
        public ?Gender $gender = null,
        public ?MaritalStatus $maritalStatus = null,
        #[SensitiveParameter] public ?string $currentPassword = null,
    ) {}
}
