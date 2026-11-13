<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
final class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->slug(2), 'affects_balance' => false, 'is_active' => true];
    }
}
