<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Enums\MaritalStatus;
use App\Domain\People\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeProfile> */
final class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date_of_birth' => fake()->date(),
            'phone' => '+355692123456',
            'gender' => fake()->randomElement(Gender::cases()),
            'address' => fake()->address(),
            'university' => fake()->company(),
            'field_of_study' => fake()->jobTitle(),
            'marital_status' => fake()->randomElement(MaritalStatus::cases()),
            'source_of_hire' => 'LinkedIn',
            'has_health_insurance' => true,
            'description' => fake()->sentence(),
            'metadata' => ['t-shirt' => 'M'],
        ];
    }
}
