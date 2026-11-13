<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Project;
use App\Domain\Clients\Models\ProjectTechnology;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\Priority;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'project_id' => Project::factory(),
            // The technology must belong to the project (BR-10): attach one to the project when none is given.
            'technology_id' => fn (array $attributes) => ProjectTechnology::query()->firstOrCreate(
                ['project_id' => $attributes['project_id']],
                ['technology_id' => Technology::factory()->create()->id, 'estimated_hours' => 100],
            )->technology_id,
            'status' => TaskStatus::Todo,
            'priority' => Priority::Medium,
            'estimated_hours' => 8,
            'force_to_log' => false,
        ];
    }
}
