<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Filament\Resources\BankHolidays\Pages\ManageBankHolidays;
use Filament\Actions\CreateAction;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

it('BR-25 superadmin and hr create a bank holiday per country', function (RoleName $role) {
    $this->actingAs(userWithRole($role));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Labour Day',
            'country_id' => $country->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
        ])
        ->assertHasNoFormErrors();

    expect(BankHoliday::where('name', 'Labour Day')->where('country_id', $country->id)->exists())->toBeTrue();
})->group('BR-25')->with([RoleName::Superadmin, RoleName::Hr]);

it('BR-25 admin, assistant and employee cannot manage bank holidays but can view them', function (RoleName $role) {
    $user = userWithRole($role);
    $holiday = BankHoliday::factory()->create();

    expect($user->can('create', BankHoliday::class))->toBeFalse()
        ->and($user->can('update', $holiday))->toBeFalse()
        ->and($user->can('delete', $holiday))->toBeFalse()
        ->and($user->can('viewAny', BankHoliday::class))->toBeTrue(); // bank_holidays.view: calendar
})->group('BR-25')->with([RoleName::Admin, RoleName::Assistant, RoleName::Employee]);

it('BR-25 rejects an end date before the start date, in the form and in the database', function () {
    $this->actingAs(userWithRole(RoleName::Hr));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Bad range', 'country_id' => $country->id,
            'start_date' => '2026-05-10', 'end_date' => '2026-05-01',
        ])
        ->assertHasFormErrors(['end_date']);

    expect(fn () => BankHoliday::factory()->create(['start_date' => '2026-05-10', 'end_date' => '2026-05-01']))
        ->toThrow(QueryException::class);
})->group('BR-25');

it('BR-25 / D-08 a weekend holiday is not shifted; HR adds the observed Monday as its own entry', function () {
    $country = Country::factory()->create();

    $sunday = BankHoliday::factory()->create([
        'country_id' => $country->id, 'name' => 'Summer Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14',
    ]);
    expect($sunday->start_date->isSaturday())->toBeTrue(); // 14 Mar 2026 is a Saturday: stored as entered

    BankHoliday::factory()->create([
        'country_id' => $country->id, 'name' => 'Summer Day (observed)', 'start_date' => '2026-03-16', 'end_date' => '2026-03-16',
    ]);

    expect($country->bankHolidays()->orderBy('start_date')->pluck('start_date')->map->toDateString()->all())
        ->toBe(['2026-03-14', '2026-03-16']);
})->group('BR-25');
