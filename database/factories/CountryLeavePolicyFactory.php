<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CountryLeavePolicy> */
class CountryLeavePolicyFactory extends Factory
{
    protected $model = CountryLeavePolicy::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'leave_type_id' => LeaveType::factory(),
            'days_per_year' => 20,
            'effective_from' => '2026-01-01',
            'proration' => Proration::ByStartDate,
            'carry_over_enabled' => false,
        ];
    }
}
