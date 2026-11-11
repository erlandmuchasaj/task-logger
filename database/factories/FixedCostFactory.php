<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\FixedCost;
use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FixedCost> */
class FixedCostFactory extends Factory
{
    protected $model = FixedCost::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Management',
            'date' => '2026-09-01',
            'amount_minor' => 10000,
            'currency_code' => 'EUR',
            'created_by' => User::factory(),
        ];
    }
}
