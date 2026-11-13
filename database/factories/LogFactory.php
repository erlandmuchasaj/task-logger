<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Work\Enums\LogType;
use App\Domain\Work\Models\Log;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A raw log row for tests (bypasses the CreateLog action, so no rule is applied). Direct log by default.
 *
 * @extends Factory<Log>
 */
final class LogFactory extends Factory
{
    protected $model = Log::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => '2026-03-10',
            'project_id' => Project::factory(),
            'task_id' => null,
            'technology_id' => fn (array $attributes) => ProjectTechnology::query()->firstOrCreate(
                ['project_id' => $attributes['project_id']],
                ['technology_id' => Technology::factory()->create()->id, 'estimated_hours' => 100],
            )->technology_id,
            'duration_minutes' => 120,
            'log_type' => LogType::NormalHours,
            'description' => fake()->sentence(),
            'rate_minor' => 2000,
            'rate_currency' => 'EUR',
        ];
    }
}
