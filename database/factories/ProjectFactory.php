<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Enums\ProjectStatus;
use App\Domain\Clients\Enums\ProjectType;
use App\Domain\Clients\Models\Customer;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'customer_id' => Customer::factory(),
            // Requires RolesAndPermissionsSeeder (every Feature test seeds it): the manager must be an admin (BR-30).
            'manager_id' => User::factory()->withRole(RoleName::Admin),
            'project_type' => ProjectType::Standalone,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'can_log_directly_to_project' => false,
            'status' => ProjectStatus::Active,
            'color' => '#3b82f6',
        ];
    }
}
