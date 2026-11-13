<?php

declare(strict_types=1);

namespace App\Domain\Clients\Data;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Enums\ProjectType;
use Carbon\CarbonInterface;

final readonly class ProjectData
{
    /**
     * @param  array<int, string>  $technologies  technology_id => estimated hours ("230" or "230.50")
     * @param  list<int>  $memberIds
     */
    public function __construct(
        public int $customerId,
        public ProjectType $type,
        public string $name,
        public ?string $description,
        public int $managerId,
        public CarbonInterface $startDate,
        public ?CarbonInterface $endDate,
        public bool $canLogDirectly,
        public ProjectStatus $status,
        public ?string $color,
        public array $technologies = [],
        public array $memberIds = [],
    ) {}
}
