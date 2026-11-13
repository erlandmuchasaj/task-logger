<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\Department;
use App\Domain\MasterData\Models\Technology;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Data\HrRecordData;
use App\Domain\People\Data\SalaryData;
use App\Domain\People\Enums\Gender;
use App\Domain\People\Notifications\WelcomeEmployee;
use App\Domain\Shared\Money;
use App\Filament\Resources\Employees\Pages\CreateEmployee as CreateEmployeePage;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Notification::fake());

it('FR-01 W-01 the superadmin creates an employee with role, skills, HR record, contacts and first salary', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $laravel = Technology::factory()->create();
    $angular = Technology::factory()->create();
    $manager = userWithRole(RoleName::Admin);

    $employee = resolve(CreateEmployee::class)->handle(
        $actor,
        employeeData(['role' => RoleName::Hr, 'managerId' => $manager->id, 'skills' => [$laravel->id => 7, $angular->id => 9]]),
        new HrRecordData(
            phone: '0692123456',
            gender: Gender::Female,
            hasHealthInsurance: true,
            metadata: ['t-shirt' => 'M'],
            contacts: [['name' => 'Ana', 'phone' => '0692123457', 'relationship' => 'sister']],
        ),
        new SalaryData(Money::of(150000, 'EUR'), now()),
    );

    expect($employee->roles()->pluck('name')->all())->toBe(['hr'])
        ->and($employee->manager_id)->toBe($manager->id)
        ->and($employee->skills()->pluck('level', 'technology_id')->all())->toBe([$laravel->id => 7, $angular->id => 9])
        ->and($employee->profile->phone)->toBe('+355692123456')
        ->and($employee->emergencyContacts()->sole()->phone)->toBe('+355692123457')
        ->and($employee->profile->metadata)->toBe(['t-shirt' => 'M'])
        ->and($employee->salaries()->sole()->money()->minor)->toBe(150000)
        ->and($employee->salaries()->sole()->created_by)->toBe($actor->id);
})->group('FR-01');

it('FR-01 W-01 D-53 the new user gets a welcome email with their email and the temporary password they sign in with', function (): void {
    $employee = resolve(CreateEmployee::class)->handle(userWithRole(RoleName::Superadmin), employeeData());

    Notification::assertSentTo($employee, WelcomeEmployee::class, function (WelcomeEmployee $n) use ($employee): bool {
        $mail = $n->toMail($employee);
        $text = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

        return Hash::check($n->password, $employee->password)
            && preg_match('/[a-z]/', $n->password) && preg_match('/[A-Z]/', $n->password) && preg_match('/\d/', $n->password)
            && str_contains($text, $employee->email)
            && str_contains($text, $n->password)
            && str_contains($text, 'choose your own password')
            && $mail->actionUrl === Filament::getLoginUrl();
    });
    Notification::assertNotSentTo($employee, ResetPassword::class);
})->group('FR-01');

it('D-53 the welcome email is sent at once, not queued, so the password never reaches the jobs table', function (): void {
    expect(new WelcomeEmployee('x'))->not->toBeInstanceOf(ShouldQueue::class);
});

it('D-53 the password hash is never written to the activity log', function (): void {
    $employee = resolve(CreateEmployee::class)->handle(userWithRole(RoleName::Superadmin), employeeData());

    $created = Activity::query()->where('subject_type', $employee->getMorphClass())->where('subject_id', $employee->id)->where('event', 'created')->sole();
    expect($created->attribute_changes?->get('attributes'))->toHaveKey('email')->not->toHaveKey('password');
});

it('FR-01 only the superadmin may create employees; there is no self-registration', function (RoleName $role): void {
    resolve(CreateEmployee::class)->handle(userWithRole($role), employeeData());
})->group('FR-01')->throws(AuthorizationException::class)->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('FR-01 W-01 requires country, department, position, employment type and start date', function (): void {
    resolve(CreateEmployee::class)->handle(userWithRole(RoleName::Superadmin), employeeData([
        'countryId' => null, 'departmentId' => null, 'position' => null, 'employmentType' => null, 'startDate' => null,
    ]));
})->group('FR-01')->throws(ValidationException::class);

it('FR-01 rejects a duplicate email, including a soft-deleted account', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    User::factory()->create(['email' => 'taken@example.test'])->delete();

    resolve(CreateEmployee::class)->handle($actor, employeeData(['email' => 'taken@example.test']));
})->group('FR-01')->throws(ValidationException::class);

it('FR-12 skill levels outside 1 to 10 are rejected and nothing is created', function (int $level): void {
    $actor = userWithRole(RoleName::Superadmin);
    $before = User::query()->count();

    try {
        resolve(CreateEmployee::class)->handle($actor, employeeData(['email' => 'level@example.test', 'skills' => [Technology::factory()->create()->id => $level]]));
    } catch (ValidationException) {
    }

    expect(User::query()->count())->toBe($before)->and(User::query()->where('email', 'level@example.test')->exists())->toBeFalse();
})->group('FR-12')->with([0, 11, -1]);

it('FR-11 an invalid phone number is rejected and the whole creation rolls back', function (): void {
    $actor = userWithRole(RoleName::Superadmin);

    try {
        resolve(CreateEmployee::class)->handle($actor, employeeData(['email' => 'phone@example.test']), new HrRecordData(phone: '12'));
    } catch (ValidationException) {
    }

    expect(User::query()->where('email', 'phone@example.test')->exists())->toBeFalse();
})->group('FR-11');

it('BR-32 creating an employee is audited with the actor', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    Activity::query()->delete();

    $employee = resolve(CreateEmployee::class)->handle($actor, employeeData(), new HrRecordData(phone: '0692123456'));

    expect(Activity::query()->where('event', 'created')->where('subject_type', $employee->getMorphClass())->where('subject_id', $employee->id)->sole()->causer_id)->toBe($actor->id)
        ->and(Activity::query()->where('event', 'role_changed')->where('subject_id', $employee->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_type', 'like', '%EmployeeProfile')->where('event', 'created')->exists())->toBeTrue();
})->group('BR-32');

it('FR-01 the Create screen creates an employee end to end for the superadmin', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $country = Country::factory()->albania()->create();
    $department = Department::factory()->create();

    Livewire::test(CreateEmployeePage::class)
        ->fillForm([
            'name' => 'Ola', 'surname' => 'Berisha', 'email' => 'ola@example.test', 'role' => 'employee',
            'country_id' => $country->id, 'department_id' => $department->id, 'position' => 'Tester',
            'employment_type' => 'full_time', 'start_date' => '2026-11-02',
            'profile.phone' => '0692123456', 'salary.amount' => '1200.50', 'salary.currency' => 'ALL', 'salary.effective_date' => '2026-11-02',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $employee = User::query()->where('email', 'ola@example.test')->firstOrFail();
    expect($employee->profile->phone)->toBe('+355692123456')
        ->and($employee->salaries()->sole()->amount_minor)->toBe(120050)
        ->and($employee->salaries()->sole()->currency_code)->toBe('ALL');
    Notification::assertSentTo($employee, WelcomeEmployee::class);
})->group('FR-01');

it('FR-01 admin, hr and assistant cannot open the Create screen', function (RoleName $role): void {
    $this->actingAs(userWithRole($role))->get('/app/employees/create')->assertForbidden();
})->group('FR-01')->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);
