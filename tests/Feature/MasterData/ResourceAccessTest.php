<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Enums\WeekendHolidayRule;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\Technology;
use App\Filament\Resources\Countries\Pages\ManageCountries;
use App\Filament\Resources\Technologies\Pages\ManageTechnologies;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('NFR-03 an employee cannot open master data screens they have no permission for', function (string $url): void {
    $this->actingAs(userWithRole(RoleName::Employee))->get($url)->assertForbidden();
})->with(['/app/countries', '/app/departments', '/app/technologies', '/app/payment-plans', '/app/labels', '/app/leave-types', '/app/manage-settings']);

it('D-10 the assistant creates technologies but sees no edit or delete', function (): void {
    $this->actingAs(userWithRole(RoleName::Assistant));
    $existing = Technology::factory()->create();

    Livewire::test(ManageTechnologies::class)
        ->assertActionVisible(CreateAction::class)
        ->callAction(CreateAction::class, ['name' => 'Ionic'])
        ->assertHasNoFormErrors()
        ->assertTableActionHidden(EditAction::class, $existing)
        ->assertTableActionHidden(DeleteAction::class, $existing);

    expect(Technology::query()->where('name', 'Ionic')->exists())->toBeTrue();
});

it('FR-05 duplicate country codes are rejected', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $country = Country::factory()->create(['code' => 'AL']);

    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, [
            'name' => 'Albania again', 'code' => 'AL', 'prefix' => '+355', 'timezone' => 'Europe/Tirane',
            'currency_code' => 'ALL', 'working_days' => [1, 2, 3, 4, 5],
        ])
        ->assertHasFormErrors(['code']);

    expect(Country::query()->where('code', 'AL')->count())->toBe(1)->and($country->exists)->toBeTrue();
})->group('FR-05');

it('FR-05 a country cannot be given a currency that is not in ISO 4217', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));

    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, [
            'name' => 'Atlantis', 'code' => 'AT', 'prefix' => '+000', 'timezone' => 'Europe/Tirane',
            'currency_code' => 'ZZZ', 'working_days' => [1, 2, 3, 4, 5],
        ])
        ->assertHasFormErrors(['currency_code']);

    expect(Country::query()->where('code', 'AT')->exists())->toBeFalse();
})->group('FR-05');

it('FR-05 / FR-06 superadmin creates a country with its PTO and sick leave policies', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $annual = LeaveType::factory()->create(['name' => 'annual_leave', 'affects_balance' => true]);

    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, [
            'name' => 'Albania', 'code' => 'al', 'prefix' => '+355', 'timezone' => 'Europe/Tirane',
            'currency_code' => 'all', 'working_days' => [1, 2, 3, 4, 5],
            'leavePolicies' => [[
                'leave_type_id' => $annual->id, 'days_per_year' => 20, 'effective_from' => '2026-01-01',
                'proration' => 'by_start_date', 'carry_over_enabled' => false,
            ]],
        ])
        ->assertHasNoFormErrors();

    $country = Country::query()->where('code', 'AL')->sole();
    expect($country->currency_code)->toBe('ALL')
        ->and($country->leavePolicies()->sole()->days_per_year)->toBe('20.0');
})->group('FR-05', 'FR-06');

it('FR-06 the leave type dropdown on a country policy shows readable labels, not the stored names', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $annual = LeaveType::factory()->create(['name' => 'annual_leave', 'affects_balance' => true]);

    Livewire::test(ManageCountries::class)
        ->mountAction(CreateAction::class)
        ->fillForm(['leavePolicies' => [['days_per_year' => 22]]])
        ->assertFormFieldExists(
            'leavePolicies.0.leave_type_id',
            checkFieldUsing: fn (Select $field): bool => $field->getOptions() === [$annual->id => 'Annual Leave'],
        );
})->group('FR-06');

it('FR-05 the assistant can add a country without leave policies and never sees them', function (): void {
    $this->actingAs(userWithRole(RoleName::Assistant));

    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, [
            'name' => 'Kosovo', 'code' => 'XK', 'prefix' => '+383', 'timezone' => 'Europe/Belgrade',
            'currency_code' => 'EUR', 'working_days' => [1, 2, 3, 4, 5],
        ])
        ->assertHasNoFormErrors();

    expect(Country::query()->where('code', 'XK')->sole()->leavePolicies()->count())->toBe(0);
})->group('FR-05');

it('every M1 screen renders for a superadmin', function (string $url): void {
    $this->actingAs(userWithRole(RoleName::Superadmin))->get($url)->assertOk();
})->with(['/app/countries', '/app/departments', '/app/technologies', '/app/payment-plans', '/app/labels', '/app/leave-types', '/app/bank-holidays', '/app/manage-settings']);

it('FR-06 a country with leave policies opens for editing', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $policy = CountryLeavePolicy::factory()->create();

    Livewire::test(ManageCountries::class)
        ->mountTableAction(EditAction::class, $policy->country)
        ->assertSchemaStateSet(['name' => $policy->country->name]);
})->group('FR-06');

it('D-62 the superadmin switches a country to observing weekend holidays on the next working day, and back', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $country = Country::factory()->create(['code' => 'AL']);

    Livewire::test(ManageCountries::class)
        ->callTableAction(EditAction::class, $country, ['weekend_holiday_rule' => WeekendHolidayRule::NextWorkingDay->value])
        ->assertHasNoFormErrors();

    expect($country->refresh()->weekend_holiday_rule)->toBe(WeekendHolidayRule::NextWorkingDay)
        ->and($country->movesWeekendHolidays())->toBeTrue();

    Livewire::test(ManageCountries::class)
        ->callTableAction(EditAction::class, $country, ['weekend_holiday_rule' => WeekendHolidayRule::Stays->value])
        ->assertHasNoFormErrors();

    expect($country->refresh()->weekend_holiday_rule)->toBe(WeekendHolidayRule::Stays)
        ->and($country->movesWeekendHolidays())->toBeFalse();
})->group('D-62');

it('D-62 a new country starts with weekend holidays staying where they are, unless it is added with the rule on', function (): void {
    $fields = ['prefix' => '+383', 'timezone' => 'Europe/Belgrade', 'currency_code' => 'EUR', 'working_days' => [1, 2, 3, 4, 5]];

    $this->actingAs(userWithRole(RoleName::Assistant));
    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, ['name' => 'Kosovo', 'code' => 'XK', ...$fields])
        ->assertHasNoFormErrors();

    $this->actingAs(userWithRole(RoleName::Superadmin));
    Livewire::test(ManageCountries::class)
        ->callAction(CreateAction::class, ['name' => 'Albania', 'code' => 'AL', ...$fields, 'weekend_holiday_rule' => WeekendHolidayRule::NextWorkingDay->value])
        ->assertHasNoFormErrors();

    expect(Country::query()->where('code', 'XK')->sole()->weekend_holiday_rule)->toBe(WeekendHolidayRule::Stays)
        ->and(Country::query()->where('code', 'AL')->sole()->weekend_holiday_rule)->toBe(WeekendHolidayRule::NextWorkingDay);
})->group('D-62');

it('D-62 refuses an unknown or empty weekend holiday rule', function (mixed $value): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $country = Country::factory()->create(['code' => 'AL']);

    Livewire::test(ManageCountries::class)
        ->callTableAction(EditAction::class, $country, ['weekend_holiday_rule' => $value])
        ->assertHasFormErrors(['weekend_holiday_rule']);

    expect($country->refresh()->weekend_holiday_rule)->toBe(WeekendHolidayRule::Stays);
})->with(['an unknown value' => 'sometimes', 'an empty value' => null])->group('D-62');

it('D-62 only a role that manages countries can change the rule: the assistant has no edit action, HR and employees no screen', function (): void {
    $country = Country::factory()->create(['code' => 'AL']);

    $this->actingAs(userWithRole(RoleName::Assistant));
    Livewire::test(ManageCountries::class)->assertTableActionHidden(EditAction::class, $country);

    foreach ([RoleName::Hr, RoleName::Employee] as $role) {
        $this->actingAs(userWithRole($role))->get('/app/countries')->assertForbidden();
    }

    expect($country->refresh()->weekend_holiday_rule)->toBe(WeekendHolidayRule::Stays);
})->group('D-62');
