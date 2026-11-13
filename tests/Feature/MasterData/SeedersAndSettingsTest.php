<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;
use App\Domain\MasterData\Settings\LeaveSettings;
use App\Domain\MasterData\Settings\PrivacySettings;
use App\Domain\Shared\Money;
use App\Filament\Pages\ManageSettings;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TechnologySeeder;
use Livewire\Livewire;

it('D-19 seeds the leave types, labels and payment plans named in the spec, idempotently', function (): void {
    $this->seed(MasterDataSeeder::class);
    $this->seed(MasterDataSeeder::class);

    expect(LeaveType::query()->pluck('name')->sort()->values()->all())
        ->toBe(['annual_leave', 'parental_leave', 'personal_leave', 'sick_leave', 'unpaid_leave'])
        ->and(LeaveType::query()->where('affects_balance', true)->pluck('name')->sort()->values()->all())->toBe(['annual_leave', 'sick_leave'])
        ->and(Label::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Bug', 'Design', 'Documentation', 'Enhancement', 'Feature', 'Refactoring', 'Testing'])
        ->and(PaymentPlan::query()->count())->toBe(4);
});

it('seeds countries, departments and technologies idempotently', function (): void {
    foreach ([CountrySeeder::class, DepartmentSeeder::class, TechnologySeeder::class] as $seeder) {
        $this->seed($seeder);
        $this->seed($seeder);
    }

    $albania = Country::query()->where('code', 'AL')->firstOrFail();

    expect(Country::query()->count())->toBe(2)
        ->and($albania->currency_code)->toBe('ALL')
        ->and($albania->timezone)->toBe('Europe/Tirane')
        ->and($albania->working_days)->toBe([1, 2, 3, 4, 5])
        ->and(Technology::query()->count())->toBe(13)
        ->and(Department::query()->count())->toBe(11)
        ->and(Department::query()->where('name', 'Backend')->firstOrFail()->parent->name)->toBe('Engineering')
        ->and(Department::query()->pluck('slug')->unique()->count())->toBe(11);
});

it('D-15 / D-08 settings default to 2 months retention and carry-over off', function (): void {
    expect(resolve(PrivacySettings::class)->anonymize_after_months)->toBe(2)
        ->and(resolve(LeaveSettings::class)->carry_over_default)->toBeFalse();
});

it('settings.manage holders (superadmin, admin, hr) can change settings', function (RoleName $role): void {
    $this->actingAs(userWithRole($role));

    Livewire::test(ManageSettings::class)
        ->fillForm(['anonymize_after_months' => 3, 'carry_over_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(resolve(PrivacySettings::class)->anonymize_after_months)->toBe(3)
        ->and(resolve(LeaveSettings::class)->carry_over_default)->toBeTrue();
})->with([RoleName::Superadmin, RoleName::Admin, RoleName::Hr]);

it('assistants and employees cannot open settings', function (RoleName $role): void {
    $this->actingAs(userWithRole($role))->get('/app/manage-settings')->assertForbidden();
})->with([RoleName::Assistant, RoleName::Employee]);

it('BR-12 every seeded country uses a currency from the ISO 4217 table', function (): void {
    $this->seed(CountrySeeder::class);

    expect(Country::query()->pluck('currency_code')->unique()->reject(fn (string $code): bool => Money::isIsoCurrency($code))->all())->toBe([]);
})->group('BR-12');
