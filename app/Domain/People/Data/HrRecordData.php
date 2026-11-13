<?php

declare(strict_types=1);

namespace App\Domain\People\Data;

use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use Carbon\CarbonInterface;

/** The private HR record plus emergency contacts (D-14). Phones may be entered in any valid format. */
final readonly class HrRecordData
{
    /**
     * @param  list<array{id?: int|null, name: string, phone: string, relationship: string}>  $contacts
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public ?CarbonInterface $dateOfBirth = null,
        public ?string $phone = null,
        public ?Gender $gender = null,
        public ?string $address = null,
        public ?string $university = null,
        public ?string $fieldOfStudy = null,
        public ?MaritalStatus $maritalStatus = null,
        public ?string $sourceOfHire = null,
        public bool $hasHealthInsurance = false,
        public ?string $description = null,
        public array $metadata = [],
        public array $contacts = [],
    ) {}
}
