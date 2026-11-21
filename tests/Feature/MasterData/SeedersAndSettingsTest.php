<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Enums\WeekendHolidayRule;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Label;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\MasterData\Models\PaymentPlan;
use App\Domain\MasterData\Models\Technology;
use App\Domain\MasterData\Settings\LeaveSettings;
use App\Domain\MasterData\Settings\PrivacySettings;
use App\Domain\MasterData\Support\BankHolidays;
use App\Domain\MasterData\Support\HolidayOccurrence;
use App\Domain\Shared\Money;
use App\Filament\Pages\ManageSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AlbaniaLeaveSeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LocalDemoSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\RoleUsersSeeder;
use Database\Seeders\SuperadminSeeder;
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

it('gives every seeded department and label its own color', function (): void {
    $this->seed([DepartmentSeeder::class, MasterDataSeeder::class]);

    foreach ([Department::query()->pluck('color'), Label::query()->pluck('color')] as $colors) {
        expect($colors->filter()->count())->toBe($colors->count())
            ->and($colors->unique()->count())->toBe($colors->count());
    }
});

it('fills a missing color on re-seed but keeps a color an admin chose', function (): void {
    $this->seed([DepartmentSeeder::class]);
    Department::query()->where('name', 'Sales')->update(['color' => '#000000']);
    Department::query()->where('name', 'Design')->update(['color' => null]);

    $this->seed([DepartmentSeeder::class]);

    expect(Department::query()->where('name', 'Sales')->value('color'))->toBe('#000000')
        ->and(Department::query()->where('name', 'Design')->value('color'))->toBe('#ec4899');
});

it('D-15 / D-08 settings default to 2 months retention and carry-over off', function (): void {
    expect(resolve(PrivacySettings::class)->anonymize_after_months)->toBe(2)
        ->and(resolve(LeaveSettings::class)->carry_over_default)->toBeFalse();
});

it('D-64 the superadmin can change settings', function (RoleName $role): void {
    $this->actingAs(userWithRole($role));

    Livewire::test(ManageSettings::class)
        ->fillForm(['anonymize_after_months' => 3, 'carry_over_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(resolve(PrivacySettings::class)->anonymize_after_months)->toBe(3)
        ->and(resolve(LeaveSettings::class)->carry_over_default)->toBeTrue();
})->with([RoleName::Superadmin])->group('D-64');

it('D-64 admin, HR, finance, assistants and employees cannot open settings', function (RoleName $role): void {
    $this->actingAs(userWithRole($role))->get('/app/manage-settings')->assertForbidden();
})->with([RoleName::Admin, RoleName::Hr, RoleName::Finance, RoleName::Assistant, RoleName::Employee])->group('D-64');

it('BR-12 every seeded country uses a currency from the ISO 4217 table', function (): void {
    $this->seed(CountrySeeder::class);

    expect(Country::query()->pluck('currency_code')->unique()->reject(fn (string $code): bool => Money::isIsoCurrency($code))->all())->toBe([]);
})->group('BR-12');

it('seeds the Albania 22-day policy and 2026 bank holidays idempotently', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class]);
    $this->seed(AlbaniaLeaveSeeder::class);
    $this->seed(AlbaniaLeaveSeeder::class);

    $albania = Country::query()->where('code', 'AL')->firstOrFail();
    $policy = CountryLeavePolicy::query()->where('country_id', $albania->id)->sole();

    expect(Country::query()->count())->toBe(2)
        ->and(BankHoliday::query()->where('country_id', $albania->id)->count())->toBe(15)
        ->and(BankHoliday::query()->where('country_id', '!=', $albania->id)->count())->toBe(0)
        ->and($policy->leaveType->name)->toBe('annual_leave')
        ->and((string) $policy->days_per_year)->toBe('22.0')
        ->and($policy->effective_from->toDateString())->toBe('2026-01-01')
        ->and($policy->proration)->toBe(Proration::ByStartDate)
        ->and($policy->carry_over_enabled)->toBeFalse();
});

it('D-62 seeds Albania as a country that moves weekend holidays, Kosovo as one that does not, and stores no observed day', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);

    expect(Country::query()->where('code', 'AL')->firstOrFail()->weekend_holiday_rule)->toBe(WeekendHolidayRule::NextWorkingDay)
        ->and(Country::query()->where('code', 'XK')->firstOrFail()->weekend_holiday_rule)->toBe(WeekendHolidayRule::Stays)
        ->and(BankHoliday::query()->where('name', 'like', '%(observed)')->count())->toBe(0);
})->group('D-62');

it('D-62 works out the 8 observed days of the Bank of Albania 2026 list from the 15 seeded holidays', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);

    $albania = Country::query()->where('code', 'AL')->firstOrFail();
    $observed = BankHolidays::occurrences([(int) $albania->getKey()], '2026-01-01', '2026-12-31')
        ->filter(fn (HolidayOccurrence $occurrence): bool => $occurrence->observed)
        ->map(fn (HolidayOccurrence $occurrence): string => $occurrence->start->toDateString())
        ->values()->all();

    expect($observed)->toBe(['2026-03-16', '2026-03-23', '2026-04-06', '2026-04-13', '2026-09-07', '2026-11-23', '2026-11-30', '2026-12-01']);
})->group('D-62');

it('charges two days for 27 Nov to 2 Dec 2026 in Albania, the weekend holidays being observed on Monday and Tuesday', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);

    $albania = Country::query()->where('code', 'AL')->firstOrFail();
    $span = new LeaveSpan(CarbonImmutable::parse('2026-11-27'), CarbonImmutable::parse('2026-12-02'));

    expect($span->chargedTenths($albania))->toBe(20);
});

it('places the role logins in Albania and grants them the 22-day annual leave, idempotently', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);
    $this->seed(RoleUsersSeeder::class);
    $this->seed(RoleUsersSeeder::class);

    $employee = User::query()->where('email', 'employee@test.com')->firstOrFail();
    $annual = LeaveType::query()->where('name', 'annual_leave')->firstOrFail();

    expect($employee->country->code)->toBe('AL')
        ->and($employee->start_date->toDateString())->toBe('2025-01-01')
        ->and(LeaveBalance::balance($employee, $annual->id))->toBe(220);
});

it('places the configured superadmin in Albania and grants the 22-day annual leave outside production', function (): void {
    config(['task-logger.superadmin.email' => 'root@example.com', 'task-logger.superadmin.password' => 'a-long-Passw0rd-1']);

    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);
    $this->seed(SuperadminSeeder::class);
    $this->seed(SuperadminSeeder::class);

    $superadmin = User::query()->where('email', 'root@example.com')->firstOrFail();
    $annual = LeaveType::query()->where('name', 'annual_leave')->firstOrFail();

    expect($superadmin->country->code)->toBe('AL')
        ->and(LeaveBalance::balance($superadmin, $annual->id))->toBe(220);
});

it('D-58 creates the role logins and the superadmin in production too, idempotently', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class]);

    app()->detectEnvironment(fn (): string => 'production');
    config(['task-logger.superadmin.email' => 'root@example.com', 'task-logger.superadmin.password' => 'a-long-Passw0rd-1']);

    foreach (range(1, 2) as $_) {
        $this->artisan('db:seed', ['--class' => RoleUsersSeeder::class, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => SuperadminSeeder::class, '--force' => true])->assertSuccessful();
    }

    expect(User::query()->whereIn('email', array_map(fn (RoleName $role): string => "{$role->value}@test.com", RoleName::cases()))->count())
        ->toBe(count(RoleName::cases()))
        ->and(User::query()->where('email', 'root@example.com')->firstOrFail()->hasRole(RoleName::Superadmin->value))->toBeTrue();
})->group('D-58');

it('D-58 creates the superadmin from the config defaults when no credentials are set', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class, SuperadminSeeder::class]);

    expect(User::query()->where('email', 'superadmin@test.com')->firstOrFail()->hasRole(RoleName::Superadmin->value))->toBeTrue();
})->group('D-58');

it('D-58 skips the demo data outside local, and seeds it when --force is passed', function (): void {
    $this->seed([MasterDataSeeder::class, CountrySeeder::class, AlbaniaLeaveSeeder::class, TechnologySeeder::class]);

    $this->artisan('db:seed', ['--class' => LocalDemoSeeder::class])->assertSuccessful();
    expect(Customer::query()->count())->toBe(0);

    $this->artisan('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true])->assertSuccessful();
    expect(Customer::query()->count())->toBeGreaterThan(0);

    $customers = Customer::query()->count();
    $this->artisan('db:seed', ['--class' => LocalDemoSeeder::class, '--force' => true])->assertSuccessful();
    expect(Customer::query()->count())->toBe($customers);
})->group('D-58');
