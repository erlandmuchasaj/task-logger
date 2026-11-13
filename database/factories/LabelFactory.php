<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Label> */
class LabelFactory extends Factory
{
    protected $model = Label::class;

    public function definition(): array
    {
        return ['name' => fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 9999), 'color' => null];
    }
}
