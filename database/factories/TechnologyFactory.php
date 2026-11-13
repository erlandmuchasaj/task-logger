<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\Technology;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Technology> */
final class TechnologyFactory extends Factory
{
    protected $model = Technology::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 9999), 'description' => fake()->sentence()];
    }
}
