<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
final class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'created_by' => fn (array $attributes) => $attributes['user_id'],
            'leave_type_id' => LeaveType::factory(),
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-01',
            'start_day_part' => DayPart::Full,
            'end_day_part' => DayPart::Full,
            'days_requested' => '1.0',
            'status' => LeaveStatus::Pending,
        ];
    }
}
