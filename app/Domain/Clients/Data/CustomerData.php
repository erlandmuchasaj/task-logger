<?php

declare(strict_types=1);

namespace App\Domain\Clients\Data;

use App\Domain\Shared\Priority;

final readonly class CustomerData
{
    public function __construct(
        public string $name,
        public ?string $surname,
        public string $email,
        public int $countryId,
        public Priority $priority,
        public ?string $phone = null,
        public ?string $address = null,
        public ?string $companyName = null,
        public ?int $paymentPlanId = null,
        public ?string $currencyCode = null,
        public ?string $avatarPath = null,
        public bool $isActive = true,
    ) {}
}
