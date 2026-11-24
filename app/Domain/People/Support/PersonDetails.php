<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

/**
 * D-89: what the read-only profile page shows about one person. `showsPrivate` is true for the person themselves and
 * for a holder of the HR-record right; only then are phone and university filled and their rows shown (an empty value
 * reads "—"). Everything else here is visible to every role that holds `hierarchy.view`.
 */
final readonly class PersonDetails
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $position,
        public ?string $avatarUrl,
        public string $initials,
        public ?string $department,
        public ?string $color,
        public string $email,
        public ?int $managerId,
        public ?string $managerName,
        public bool $showsPrivate,
        public ?string $phone,
        public ?string $university,
    ) {}
}
