<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\EmergencyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmergencyContact> */
final class EmergencyContactFactory extends Factory
{
    protected $model = EmergencyContact::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'phone' => '+355692123457',
            'relationship' => fake()->randomElement(['spouse', 'brother', 'sister', 'parent']),
        ];
    }
}
