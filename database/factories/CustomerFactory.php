<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\Shared\Priority;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
final class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'country_id' => Country::factory(),
            'phone' => '+355692123456',
            'address' => fake()->address(),
            'company_name' => fake()->company(),
            'priority' => Priority::Medium,
            'currency_code' => 'EUR',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
