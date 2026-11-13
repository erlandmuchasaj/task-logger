<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Work\Enums\LogType;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Input for one log. Set `taskId` for a task log (project and technology come from the task), or
 * `projectId` and `technologyId` for a direct log (BR-02). Time is entered as hours and minutes
 * (BR-04) and carried as total minutes.
 */
final readonly class LogData
{
    public function __construct(
        public CarbonInterface $date,
        public int $minutes,
        public LogType $type,
        public ?string $description = null,
        public ?int $taskId = null,
        public ?int $projectId = null,
        public ?int $technologyId = null,
    ) {}

    /** @throws ValidationException when minutes are not 0 to 59 or hours are negative */
    public static function minutesFrom(int $hours, int $minutes): int
    {
        if ($minutes < 0 || $minutes > 59) {
            throw ValidationException::withMessages(['minutes' => 'Minutes must be between 0 and 59.']);
        }

        if ($hours < 0) {
            throw ValidationException::withMessages(['hours' => 'Hours cannot be negative.']);
        }

        return $hours * 60 + $minutes;
    }
}
