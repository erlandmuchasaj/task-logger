<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankHoliday> */
final class BankHolidayFactory extends Factory
{
    protected $model = BankHoliday::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'country_id' => Country::factory(),
            'repeats_yearly' => false,
            'repeat_until_year' => null,
        ];
    }

    public function repeating(?int $untilYear = null): self
    {
        return $this->state(fn (): array => ['repeats_yearly' => true, 'repeat_until_year' => $untilYear]);
    }
}
