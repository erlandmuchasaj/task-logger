<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\OffboardingStatus;
use App\Domain\People\Models\Offboarding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offboarding> */
class OffboardingFactory extends Factory
{
    protected $model = Offboarding::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'end_date' => '2026-06-30',
            'equipment_returned' => false,
            'resignation_letter_signed' => false,
            'status' => OffboardingStatus::Pending,
        ];
    }
}
