<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Performance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Performance> */
final class PerformanceFactory extends Factory
{
    protected $model = Performance::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'score' => 7,
            'notes' => 'Solid quarter.',
            'review_date' => '2026-06-30',
        ];
    }
}
