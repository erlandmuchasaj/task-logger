<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'description' => fake()->sentence(), 'is_active' => true];
    }
}
