<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Country> */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->country(),
            'code' => fake()->unique()->lexify('??'),
            'prefix' => '+'.fake()->numberBetween(1, 999),
            'timezone' => 'Europe/Tirane',
            'currency_code' => 'EUR',
            'working_days' => [1, 2, 3, 4, 5],
        ];
    }
}
