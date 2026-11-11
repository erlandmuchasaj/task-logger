<?php

declare(strict_types=1);

use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('FR-06 the active policy is the latest effective_from on or before the date', function () {
    $country = Country::factory()->create();
    $type = LeaveType::factory()->create();
    CountryLeavePolicy::factory()->create(['country_id' => $country->id, 'leave_type_id' => $type->id, 'days_per_year' => 20, 'effective_from' => '2025-01-01']);
    CountryLeavePolicy::factory()->create(['country_id' => $country->id, 'leave_type_id' => $type->id, 'days_per_year' => 22, 'effective_from' => '2026-01-01']);

    $on = fn (string $date) => CountryLeavePolicy::activeOn($country->id, $type->id, Carbon::parse($date))->first()?->days_per_year;

    expect($on('2024-12-31'))->toBeNull()
        ->and($on('2025-06-01'))->toBe('20.0')
        ->and($on('2026-01-01'))->toBe('22.0')
        ->and($on('2026-11-11'))->toBe('22.0');
})->group('FR-06');

it('FR-06 a country has one policy per leave type and effective date', function () {
    $country = Country::factory()->create();
    $type = LeaveType::factory()->create();
    CountryLeavePolicy::factory()->create(['country_id' => $country->id, 'leave_type_id' => $type->id, 'effective_from' => '2026-01-01']);

    expect(fn () => CountryLeavePolicy::factory()->create(['country_id' => $country->id, 'leave_type_id' => $type->id, 'effective_from' => '2026-01-01']))
        ->toThrow(QueryException::class);
})->group('FR-06');

it('D-08 carry-over is off by default and countries default to a Monday to Friday week', function () {
    $policy = CountryLeavePolicy::factory()->create();
    $country = Country::create(['name' => 'Testland', 'code' => 'TL', 'prefix' => '+1', 'timezone' => 'UTC', 'currency_code' => 'EUR']);

    expect($policy->fresh()->carry_over_enabled)->toBeFalse()
        ->and($country->fresh()->working_days)->toBe([1, 2, 3, 4, 5])
        ->and($country->isWorkingDay(6))->toBeFalse();
});
