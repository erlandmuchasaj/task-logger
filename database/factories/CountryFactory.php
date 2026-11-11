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
            'name' => fake()->unique()->country().' '.fake()->unique()->numberBetween(1, 99999),
            'code' => fake()->unique()->regexify('[B-Z][A-Z]'), // never AL: tests use albania() for a real region
            'prefix' => '+'.fake()->numberBetween(1, 999),
            'timezone' => 'Europe/Tirane',
            'currency_code' => 'EUR',
            'working_days' => [1, 2, 3, 4, 5],
        ];
    }

    /** A real region, so national-format phone numbers validate. */
    public function albania(): static
    {
        return $this->state(fn () => [
            'name' => 'Albania', 'code' => 'AL', 'prefix' => '+355', 'timezone' => 'Europe/Tirane', 'currency_code' => 'ALL',
        ]);
    }
}
