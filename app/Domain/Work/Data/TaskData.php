<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Shared\Priority;
use App\Domain\Work\Enums\TaskStatus;
use Carbon\CarbonInterface;

final readonly class TaskData
{
    /** @param  list<int>  $labelIds */
    public function __construct(
        public string $name,
        public ?string $description,
        public int $projectId,
        public int $technologyId,
        public TaskStatus $status,
        public Priority $priority,
        public string $estimatedHours = '0',
        public ?int $assigneeId = null,
        public ?int $parentId = null,
        public bool $forceToLog = false,
        public ?CarbonInterface $dueDate = null,
        public array $labelIds = [],
    ) {}
}
