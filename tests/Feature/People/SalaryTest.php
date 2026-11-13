<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ActivateDueSalaries;
use App\Domain\People\Actions\RecordSalary;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use App\Domain\Shared\Money;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\RelationManagers\SalariesRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function salary(User $actor, User $employee, int $minor, string $date, string $currency = 'EUR'): Salary
{
    return app(RecordSalary::class)->handle($actor, $employee, new SalaryData(Money::of($minor, $currency), Carbon::parse($date)));
}

function statuses(User $employee): array
{
    return $employee->salaries()->orderBy('effective_date')->orderBy('id')->pluck('status')->map->value->all();
}

it('BR-29 the first salary is active and money is integer minor units with a currency', function () {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);

    $salary = salary($hr, $employee, 150000, '2026-01-01', 'EUR');

    expect($salary->status)->toBe(SalaryStatus::Active)
        ->and($salary->amount_minor)->toBe(150000)
        ->and($salary->currency_code)->toBe('EUR')
        ->and($salary->created_by)->toBe($hr->id)
        ->and($salary->money()->equals(Money::of(150000, 'EUR')))->toBeTrue();
})->group('BR-29');

it('BR-29 a later salary supersedes the previous one and history is kept', function () {
    $actor = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);

    salary($actor, $employee, 100000, '2025-01-01');
    salary($actor, $employee, 120000, '2026-01-01');

    expect(statuses($employee))->toBe(['superseded', 'active'])
        ->and($employee->salaries()->count())->toBe(2)
        ->and(Salary::currentOn($employee->id, now())->sole()->amount_minor)->toBe(120000);
})->group('BR-29');

it('BR-29 a future-dated salary is pending until its date, then a daily job activates it', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    salary($actor, $employee, 100000, '2026-01-01');
    salary($actor, $employee, 130000, now()->addDays(10)->toDateString());

    expect(statuses($employee))->toBe(['active', 'pending'])
        ->and(Salary::currentOn($employee->id, now())->sole()->amount_minor)->toBe(100000);

    $this->travel(11)->days();
    expect(app(ActivateDueSalaries::class)->handle())->toBe(1)
        ->and(statuses($employee))->toBe(['superseded', 'active'])
        ->and(Salary::currentOn($employee->id, now())->sole()->amount_minor)->toBe(130000);

    expect(app(ActivateDueSalaries::class)->handle())->toBe(0); // idempotent
})->group('BR-29');

it('BR-29 a back-dated correction lands in history without replacing the current salary', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    salary($actor, $employee, 120000, '2026-01-01');

    salary($actor, $employee, 90000, '2024-01-01');

    expect(statuses($employee))->toBe(['superseded', 'active'])
        ->and(Salary::currentOn($employee->id, now())->sole()->amount_minor)->toBe(120000);
})->group('BR-29');

it('BR-29 salary history is append-only: amount, currency, date and note never change', function (string $attribute, mixed $value) {
    $salary = Salary::factory()->create();

    $salary->update([$attribute => $value]);
})->group('BR-29')->throws(LogicException::class)->with([
    ['amount_minor', 1], ['currency_code', 'USD'], ['effective_date', '2030-01-01'], ['description', 'edited'],
]);

it('BR-29 a salary row can never be deleted', function () {
    Salary::factory()->create()->delete();
})->group('BR-29')->throws(LogicException::class);

it('BR-29 only the status of a row can move', function () {
    $salary = Salary::factory()->create(['status' => SalaryStatus::Active]);

    $salary->update(['status' => SalaryStatus::Superseded]);

    expect($salary->fresh()->status)->toBe(SalaryStatus::Superseded);
})->group('BR-29');

it('BR-29 a salary must be greater than zero', function () {
    salary(userWithRole(RoleName::Hr), userWithRole(RoleName::Employee), 0, '2026-01-01');
})->group('BR-29')->throws(ValidationException::class);

it('BR-29 only superadmin, admin and hr record salaries', function (RoleName $role, bool $allowed) {
    $employee = userWithRole(RoleName::Employee);
    $record = fn () => salary(userWithRole($role), $employee, 100000, '2026-01-01');

    $allowed ? expect($record()->exists)->toBeTrue() : expect($record)->toThrow(AuthorizationException::class);
})->group('BR-29')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, true],
    [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('BR-29 salaries are visible only to superadmin, admin and hr', function (RoleName $role, bool $visible) {
    $employee = userWithRole(RoleName::Employee);
    $salary = Salary::factory()->create(['user_id' => $employee->id]);
    $user = userWithRole($role);

    expect($user->can('viewAny', Salary::class))->toBe($visible)
        ->and($user->can('view', $salary))->toBe($visible)
        ->and($user->can('viewSalaries', $employee))->toBe($visible)
        ->and($user->can('update', $salary))->toBeFalse()
        ->and($user->can('delete', $salary))->toBeFalse();
})->group('BR-29')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, true],
    [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('BR-29 the Salaries tab lists history and adds a row for HR, with no edit or delete', function () {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $existing = Salary::factory()->create(['user_id' => $employee->id, 'created_by' => $hr->id]);
    $this->actingAs($hr);

    Livewire::test(SalariesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertCanSeeTableRecords([$existing])
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->callAction(TestAction::make('create')->table(), ['amount' => '1800.00', 'currency' => 'eur', 'effective_date' => '2026-06-01', 'description' => 'Promotion'])
        ->assertHasNoFormErrors();

    expect($employee->salaries()->count())->toBe(2)
        ->and($employee->salaries()->where('description', 'Promotion')->sole()->amount_minor)->toBe(180000);
})->group('BR-29');

it('BR-29 an employee cannot see the Salaries tab', function () {
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($employee);

    expect(SalariesRelationManager::canViewForRecord($employee, EditEmployee::class))->toBeFalse();
})->group('BR-29');
