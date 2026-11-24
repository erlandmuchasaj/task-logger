<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * D-77, D-90: what a person may change about themselves. Role, manager, salary and employment fields are not here.
 * The avatar path is the whole new state of the photo: null removes it.
 */
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
        public ?string $avatarPath = null,
        #[SensitiveParameter] public ?string $currentPassword = null,
    ) {}
}
