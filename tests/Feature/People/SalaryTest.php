<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ActivateDueSalaries;
use App\Domain\People\Actions\CorrectSalary;
use App\Domain\People\Actions\RecordSalary;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\Salary;
use App\Domain\Shared\Money;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\RelationManagers\SalariesRelationManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function salary(User $actor, User $employee, int $minor, string $date, string $currency = 'EUR'): Salary
{
    return resolve(RecordSalary::class)->handle($actor, $employee, new SalaryData(Money::of($minor, $currency), Date::parse($date)));
}

function statuses(User $employee): array
{
    return $employee->salaries()->oldest('effective_date')->orderBy('id')->pluck('status')->map->value->all();
}

it('BR-29 the first salary is active and money is integer minor units with a currency', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);

    $salary = salary($hr, $employee, 150000, '2026-01-01', 'EUR');

    expect($salary->status)->toBe(SalaryStatus::Active)
        ->and($salary->amount_minor)->toBe(150000)
        ->and($salary->currency_code)->toBe('EUR')
        ->and($salary->created_by)->toBe($hr->id)
        ->and($salary->money()->equals(Money::of(150000, 'EUR')))->toBeTrue();
})->group('BR-29');

it('BR-29 a later salary supersedes the previous one and history is kept', function (): void {
    $actor = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);

    salary($actor, $employee, 100000, '2025-01-01');
    salary($actor, $employee, 120000, '2026-01-01');

    expect(statuses($employee))->toBe(['superseded', 'active'])
        ->and($employee->salaries()->count())->toBe(2)
        ->and(Salary::query()->currentOn($employee->id, now())->sole()->amount_minor)->toBe(120000);
})->group('BR-29');

it('BR-29 a future-dated salary is pending until its date, then a daily job activates it', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    salary($actor, $employee, 100000, '2026-01-01');
    salary($actor, $employee, 130000, now()->addDays(10)->toDateString());

    expect(statuses($employee))->toBe(['active', 'pending'])
        ->and(Salary::query()->currentOn($employee->id, now())->sole()->amount_minor)->toBe(100000);

    $this->travel(11)->days();
    expect(resolve(ActivateDueSalaries::class)->handle())->toBe(1)
        ->and(statuses($employee))->toBe(['superseded', 'active'])
        ->and(Salary::query()->currentOn($employee->id, now())->sole()->amount_minor)->toBe(130000);

    expect(resolve(ActivateDueSalaries::class)->handle())->toBe(0); // idempotent
})->group('BR-29');

it('BR-29 a back-dated correction lands in history without replacing the current salary', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    salary($actor, $employee, 120000, '2026-01-01');

    salary($actor, $employee, 90000, '2024-01-01');

    expect(statuses($employee))->toBe(['superseded', 'active'])
        ->and(Salary::query()->currentOn($employee->id, now())->sole()->amount_minor)->toBe(120000);
})->group('BR-29');

it('BR-29 D-83 a plain update cannot change amount, currency, date or note; only a correction can', function (string $attribute, mixed $value): void {
    $salary = Salary::factory()->create();

    $salary->update([$attribute => $value]);
})->group('BR-29')->throws(LogicException::class)->with([
    ['amount_minor', 1], ['currency_code', 'USD'], ['effective_date', '2030-01-01'], ['description', 'edited'],
]);

it('BR-29 a salary row can never be deleted', function (): void {
    Salary::factory()->create()->delete();
})->group('BR-29')->throws(LogicException::class);

it('BR-29 only the status of a row can move', function (): void {
    $salary = Salary::factory()->create(['status' => SalaryStatus::Active]);

    $salary->update(['status' => SalaryStatus::Superseded]);

    expect($salary->fresh()->status)->toBe(SalaryStatus::Superseded);
})->group('BR-29');

it('BR-29 a salary must be greater than zero', function (): void {
    salary(userWithRole(RoleName::Hr), userWithRole(RoleName::Employee), 0, '2026-01-01');
})->group('BR-29')->throws(ValidationException::class);

it('BR-29 only superadmin, admin and hr record salaries', function (RoleName $role, bool $allowed): void {
    $employee = userWithRole(RoleName::Employee);
    $record = fn (): Salary => salary(userWithRole($role), $employee, 100000, '2026-01-01');

    $allowed ? expect($record()->exists)->toBeTrue() : expect($record)->toThrow(AuthorizationException::class);
})->group('BR-29')->with([
    [RoleName::Superadmin, true], [RoleName::Admin, true], [RoleName::Hr, true],
    [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('BR-29 D-83 salaries are visible to superadmin, admin and hr, corrected by superadmin and hr, and deleted by nobody', function (RoleName $role, bool $visible, bool $corrects): void {
    $employee = userWithRole(RoleName::Employee);
    $salary = Salary::factory()->create(['user_id' => $employee->id]);
    $user = userWithRole($role);

    expect($user->can('viewAny', Salary::class))->toBe($visible)
        ->and($user->can('view', $salary))->toBe($visible)
        ->and($user->can('viewSalaries', $employee))->toBe($visible)
        ->and($user->can('update', $salary))->toBe($corrects)
        ->and($user->can('delete', $salary))->toBeFalse();
})->group('BR-29', 'D-83')->with([
    [RoleName::Superadmin, true, true], [RoleName::Admin, true, false], [RoleName::Hr, true, true],
    [RoleName::Assistant, false, false], [RoleName::Employee, false, false],
]);

function correction(int $minor, string $date, string $currency = 'EUR', ?string $note = null): SalaryData
{
    return new SalaryData(Money::of($minor, $currency), Date::parse($date), $note);
}

it('D-83 HR corrects the amount, currency, date and note of a row and the audit keeps both values', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $row = salary($hr, $employee, 1500000, '2026-01-01');

    $corrected = resolve(CorrectSalary::class)->handle($hr, $row, correction(150000, '2026-02-01', 'ALL', 'Typo fixed'));

    expect($corrected->amount_minor)->toBe(150000)
        ->and($corrected->currency_code)->toBe('ALL')
        ->and($corrected->effective_date->toDateString())->toBe('2026-02-01')
        ->and($corrected->description)->toBe('Typo fixed')
        ->and($corrected->created_by)->toBe($hr->id)
        ->and($corrected->user_id)->toBe($employee->id)
        ->and($employee->salaries()->count())->toBe(1);

    $entry = Activity::query()->where('subject_type', $row->getMorphClass())->where('subject_id', $row->id)->where('event', 'updated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->attribute_changes['old']['amount_minor'])->toBe(1500000)
        ->and($entry->attribute_changes['attributes']['amount_minor'])->toBe(150000);
})->group('D-83', 'BR-29');

it('D-83 a correction settles the statuses again when the date moves', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    salary($actor, $employee, 100000, '2025-01-01');
    $latest = salary($actor, $employee, 120000, '2026-01-01');

    expect(statuses($employee))->toBe(['superseded', 'active']);

    resolve(CorrectSalary::class)->handle($actor, $latest, correction(120000, now()->addMonths(2)->toDateString()));

    expect(statuses($employee))->toBe(['active', 'pending'])
        ->and(Salary::query()->currentOn($employee->id, now())->sole()->amount_minor)->toBe(100000);
})->group('D-83', 'BR-29');

it('D-83 only superadmin and hr correct a salary', function (RoleName $role, bool $allowed): void {
    $employee = userWithRole(RoleName::Employee);
    $row = Salary::factory()->create(['user_id' => $employee->id, 'amount_minor' => 100000, 'currency_code' => 'EUR']);
    $correct = fn (): Salary => resolve(CorrectSalary::class)->handle(userWithRole($role), $row, correction(90000, '2026-03-01'));

    $allowed ? expect($correct()->amount_minor)->toBe(90000) : expect($correct)->toThrow(AuthorizationException::class);

    expect($row->fresh()->amount_minor)->toBe($allowed ? 90000 : 100000);
})->group('D-83')->with([
    [RoleName::Superadmin, true], [RoleName::Hr, true],
    [RoleName::Admin, false], [RoleName::Assistant, false], [RoleName::Employee, false],
]);

it('D-83 a corrected salary must still be greater than zero', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $row = salary($hr, userWithRole(RoleName::Employee), 100000, '2026-01-01');

    expect(fn () => resolve(CorrectSalary::class)->handle($hr, $row, correction(0, '2026-01-01')))->toThrow(ValidationException::class)
        ->and($row->fresh()->amount_minor)->toBe(100000);
})->group('D-83', 'BR-29');

it('D-83 a salary is still never deleted, not by HR and not by root', function (): void {
    $salary = Salary::factory()->create();

    foreach ([RoleName::Hr, RoleName::Superadmin] as $role) {
        expect(userWithRole($role)->can('delete', $salary))->toBeFalse($role->value);
    }

    expect(fn () => $salary->delete())->toThrow(LogicException::class)
        ->and(Salary::query()->whereKey($salary->id)->exists())->toBeTrue();
})->group('D-83', 'BR-29');

it('BR-29 the Salaries tab lists history and adds a row for HR, with no delete', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $existing = Salary::factory()->create(['user_id' => $employee->id, 'created_by' => $hr->id]);
    $this->actingAs($hr);

    Livewire::test(SalariesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertCanSeeTableRecords([$existing])
        ->assertTableActionDoesNotExist('delete')
        ->callAction(TestAction::make('create')->table(), ['amount' => '1800.00', 'currency' => 'eur', 'effective_date' => '2026-06-01', 'description' => 'Promotion'])
        ->assertHasNoFormErrors();

    expect($employee->salaries()->count())->toBe(2)
        ->and($employee->salaries()->where('description', 'Promotion')->sole()->amount_minor)->toBe(180000);
})->group('BR-29');

it('D-83 HR corrects a row on the Salaries tab with the form filled from the row, and there is no delete', function (): void {
    $hr = userWithRole(RoleName::Hr);
    $employee = userWithRole(RoleName::Employee);
    $row = Salary::factory()->create(['user_id' => $employee->id, 'created_by' => $hr->id, 'amount_minor' => 150050, 'currency_code' => 'EUR']);
    $this->actingAs($hr);

    Livewire::test(SalariesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertTableActionVisible('edit', $row)
        ->assertTableActionDoesNotExist('delete')
        ->mountTableAction('edit', $row)
        ->assertTableActionDataSet(['amount' => '1500.50', 'currency' => 'EUR'])
        ->setTableActionData(['amount' => '1600.00', 'currency' => 'eur', 'effective_date' => '2026-05-01', 'description' => 'Fixed'])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    expect($row->fresh()->amount_minor)->toBe(160000)
        ->and($row->fresh()->description)->toBe('Fixed')
        ->and($employee->salaries()->count())->toBe(1);
})->group('D-83');

it('D-83 admin sees the Salaries tab but not the Correct action', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee);
    $row = Salary::factory()->create(['user_id' => $employee->id]);
    $this->actingAs($admin);

    Livewire::test(SalariesRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => EditEmployee::class])
        ->assertCanSeeTableRecords([$row])
        ->assertTableActionHidden('edit', $row);
})->group('D-83');

it('BR-29 an employee cannot see the Salaries tab', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $this->actingAs($employee);

    expect(SalariesRelationManager::canViewForRecord($employee, EditEmployee::class))->toBeFalse();
})->group('BR-29');
