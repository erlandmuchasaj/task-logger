<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\EmploymentType;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\People\Actions\DeactivateEmployee;
use App\Domain\People\Actions\ReactivateEmployee;
use App\Domain\People\Actions\ResetEmployeePassword;
use App\Domain\People\Actions\ResetTwoFactor;
use App\Domain\People\Actions\SendSetPasswordLink;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\People\Models\EmployeeProfile;
use App\Domain\People\Models\Salary;
use App\Domain\People\Notifications\PasswordResetByAdmin;
use App\Domain\Work\Models\Log;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\LeaveRequestsRelationManager;
use App\Filament\Resources\Employees\RelationManagers\TimesheetsRelationManager;
use App\Filament\Resources\Employees\Widgets\EmployeeStats;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('D-20 the superadmin deactivates an employee: login is blocked, sessions end, history stays', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
    Activity::query()->delete();

    resolve(DeactivateEmployee::class)->handle($actor, $employee, 'left the company');

    $employee->refresh();
    expect($employee->isBanned())->toBeTrue()
        ->and($employee->canAccessPanel(Filament::getPanel('app')))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $employee->id)->count())->toBe(0)
        ->and(User::query()->find($employee->id))->not->toBeNull()
        ->and(Activity::query()->where('event', 'deactivated')->where('subject_id', $employee->id)->sole()->causer_id)->toBe($actor->id);
});

it('D-20 a deactivated user cannot sign in', function (): void {
    Filament::setCurrentPanel('app');
    $employee = userWithRole(RoleName::Employee);
    resolve(DeactivateEmployee::class)->handle(userWithRole(RoleName::Superadmin), $employee);

    Livewire::test(Login::class)
        ->fillForm(['email' => $employee->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeFalse();
});

it('D-20 only the superadmin may deactivate or reactivate', function (RoleName $role): void {
    $employee = userWithRole(RoleName::Employee);

    resolve(DeactivateEmployee::class)->handle(userWithRole($role), $employee);
})->throws(AuthorizationException::class)->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-20 you cannot deactivate yourself or the last superadmin', function (): void {
    $only = userWithRole(RoleName::Superadmin);

    expect(fn () => resolve(DeactivateEmployee::class)->handle($only, $only))->toThrow(ValidationException::class);

    $other = userWithRole(RoleName::Superadmin);
    $admin = userWithRole(RoleName::Superadmin);
    resolve(DeactivateEmployee::class)->handle($admin, $other); // fine: two others remain
    resolve(DeactivateEmployee::class)->handle($admin, $only);  // fine: $admin remains

    expect(fn () => resolve(DeactivateEmployee::class)->handle($admin, $admin))->toThrow(ValidationException::class);
});

it('D-20 reactivation restores access and is audited', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    resolve(DeactivateEmployee::class)->handle($actor, $employee);

    resolve(ReactivateEmployee::class)->handle($actor, $employee->fresh());

    expect($employee->fresh()->isBanned())->toBeFalse()
        ->and(Activity::query()->where('event', 'reactivated')->where('subject_id', $employee->id)->exists())->toBeTrue();
});

it('D-15 an anonymised account is never reactivated', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = User::factory()->create(['anonymized_at' => now()]);
    $employee->ban();

    resolve(ReactivateEmployee::class)->handle($actor, $employee->fresh());

    expect($employee->fresh()->isBanned())->toBeTrue();
});

it('ADR-0005 the superadmin resets a lost two-factor device; others cannot', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    $employee->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $employee->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb']);

    expect(fn () => resolve(ResetTwoFactor::class)->handle(userWithRole(RoleName::Admin), $employee))->toThrow(AuthorizationException::class);

    resolve(ResetTwoFactor::class)->handle($actor, $employee);

    $employee->refresh();
    expect($employee->hasTwoFactorEnabled())->toBeFalse()
        ->and($employee->getAppAuthenticationRecoveryCodes())->toBeNull()
        ->and(Activity::query()->where('event', 'two_factor_reset')->where('subject_id', $employee->id)->sole()->causer_id)->toBe($actor->id);
});

it('W-01 the set-password link can be sent again', function (): void {
    Notification::fake();
    $employee = userWithRole(RoleName::Employee);

    resolve(SendSetPasswordLink::class)->handle($employee);
    resolve(SendSetPasswordLink::class)->handle($employee);

    Notification::assertSentToTimes($employee, ResetPassword::class, 2);
});

it('W-01 the set-password link stays valid for 72 hours', function (): void {
    expect(config('auth.passwords.users.expire'))->toBe(4320);
});

it('D-14 a user cannot change their own name or email on the profile page', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $employee->update(['name' => 'Original', 'email' => 'original@example.test']);
    Filament::setCurrentPanel('app');
    $this->actingAs($employee);

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => 'Renamed', 'email' => 'renamed@example.test'])
        ->call('save');

    expect($employee->fresh()->name)->toBe('Original')
        ->and($employee->fresh()->email)->toBe('original@example.test');
});

it('FR-01 the employee list actions follow permissions', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $employee->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    $this->actingAs(userWithRole(RoleName::Hr));
    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$employee])
        ->assertTableActionHidden('deactivate', $employee)
        ->assertTableActionHidden('resetTwoFactor', $employee);

    $this->actingAs(userWithRole(RoleName::Superadmin));
    Livewire::test(ListEmployees::class)
        ->assertTableActionVisible('deactivate', $employee)
        ->assertTableActionVisible('resetTwoFactor', $employee)
        ->callTableAction('deactivate', $employee);

    expect($employee->fresh()->isBanned())->toBeTrue();
});

it('D-53 the superadmin resets a password: the old one stops working and the new one is emailed and audited without its value', function (): void {
    Notification::fake();
    $actor = userWithRole(RoleName::Superadmin);
    $employee = User::factory()->withRole(RoleName::Employee)->create(['password' => 'Old-password-123']);

    resolve(ResetEmployeePassword::class)->handle($actor, $employee);

    $employee->refresh();
    expect(Hash::check('Old-password-123', $employee->password))->toBeFalse();
    Notification::assertSentTo($employee, PasswordResetByAdmin::class, function (PasswordResetByAdmin $n) use ($employee): bool {
        $text = implode("\n", $n->toMail($employee)->introLines);

        return Hash::check($n->password, $employee->password) && str_contains($text, $employee->email) && str_contains($text, $n->password);
    });

    $audit = Activity::query()->where('event', 'password_reset_by_admin')->where('subject_id', $employee->id)->sole();
    expect($audit->causer_id)->toBe($actor->id)
        ->and(json_encode($audit->toArray()))->not->toContain('$2y$');
});

it('D-53 only employees.update may reset a password, and never for a deactivated account', function (): void {
    Notification::fake();
    $employee = userWithRole(RoleName::Employee);

    expect(fn () => resolve(ResetEmployeePassword::class)->handle(userWithRole(RoleName::Hr), $employee))->toThrow(AuthorizationException::class);

    $actor = userWithRole(RoleName::Superadmin);
    resolve(DeactivateEmployee::class)->handle($actor, $employee, 'left');
    expect(fn () => resolve(ResetEmployeePassword::class)->handle($actor, $employee->fresh()))->toThrow(ValidationException::class);
    Notification::assertNothingSentTo($employee);
});

it('D-53 the reset-password action is in the row menu for the superadmin only', function (): void {
    Notification::fake();
    $employee = userWithRole(RoleName::Employee);

    $this->actingAs(userWithRole(RoleName::Hr));
    Livewire::test(ListEmployees::class)->assertTableActionHidden('resetPassword', $employee);

    $this->actingAs(userWithRole(RoleName::Superadmin));
    Livewire::test(ListEmployees::class)
        ->assertTableActionVisible('resetPassword', $employee)
        ->assertTableActionVisible('resendLink', $employee)
        ->callTableAction('resetPassword', $employee);

    Notification::assertSentTo($employee, PasswordResetByAdmin::class);
});

it('the employee list has status tabs, stats and toggleable columns', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $active = userWithRole(RoleName::Employee);
    $inactive = userWithRole(RoleName::Employee);
    resolve(DeactivateEmployee::class)->handle($actor, $inactive, 'left');
    $this->actingAs($actor);

    Livewire::test(ListEmployees::class)
        ->set('activeTab', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive])
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$inactive])
        ->assertCanNotSeeTableRecords([$active])
        ->assertTableColumnExists('position')
        ->assertTableColumnExists('start_date');

    Livewire::test(EmployeeStats::class)->assertSee('Total employees')->assertSee('Active employees')->assertSee('Avg salary')->assertSee('Avg tenure');
});

it('the average salary card is hidden from people who may not see salaries', function (): void {
    $this->actingAs(userWithRole(RoleName::Assistant));

    Livewire::test(EmployeeStats::class)->assertSee('Total employees')->assertDontSee('Avg salary');
});

it('the View profile slide-over shows HR fields and salary only to people allowed to see them', function (): void {
    $employee = userWithRole(RoleName::Employee);
    EmployeeProfile::factory()->create(['user_id' => $employee->id, 'phone' => '+355692123456']);
    Salary::factory()->create(['user_id' => $employee->id, 'status' => SalaryStatus::Active]);

    $this->actingAs(userWithRole(RoleName::Superadmin));
    Livewire::test(ListEmployees::class)
        ->assertTableActionVisible('view', $employee)
        ->mountTableAction('view', $employee)
        ->assertMountedActionModalSee(['Set by the person', '+355692123456', 'Current salary', $employee->email]);

    $this->actingAs(userWithRole(RoleName::Assistant));
    expect(auth()->user()->can('viewAny', User::class))->toBeFalse();
});

it('the View profile slide-over hides the salary from people without salaries.view', function (): void {
    $employee = userWithRole(RoleName::Employee);
    // No shipped role opens Employees without salaries.view; a direct grant proves the gate holds anyway.
    $viewer = userWithRole(RoleName::Employee);
    $viewer->givePermissionTo(Permissions::EMPLOYEES_UPDATE);
    $this->actingAs($viewer);

    Livewire::test(ListEmployees::class)
        ->mountTableAction('view', $employee)
        ->assertMountedActionModalSee('Job title')
        ->assertMountedActionModalDontSee(['Current salary', 'Contact', $employee->email]);
});

it('name, email, role, employment type and status are always shown; the other columns can be hidden', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $page = Livewire::test(ListEmployees::class);

    foreach (['name', 'email', 'roles.name', 'employment_type', 'status'] as $column) {
        $page->assertTableColumnExists($column, fn ($c): bool => ! $c->isToggleable() && ! $c->isToggledHiddenByDefault());
    }

    foreach (['department.name', 'position', 'manager.name', 'start_date', 'two_factor'] as $column) {
        $page->assertTableColumnExists($column, fn ($c): bool => $c->isToggleable());
    }

    expect(EmploymentType::Contractor->getIcon())->toBe(Heroicon::Briefcase);
});

it('the employee edit page shows Timesheets and Leave requests tabs to people allowed to see them', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $log = Log::factory()->create(['user_id' => $employee->id]);
    $leave = LeaveRequest::factory()->create(['user_id' => $employee->id]);
    $othersLog = Log::factory()->create();

    $this->actingAs(userWithRole(RoleName::Superadmin));
    $page = EditEmployee::class;

    Livewire::test(TimesheetsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => $page])
        ->assertCanSeeTableRecords([$log])
        ->assertCanNotSeeTableRecords([$othersLog]);
    Livewire::test(LeaveRequestsRelationManager::class, ['ownerRecord' => $employee, 'pageClass' => $page])
        ->assertCanSeeTableRecords([$leave]);

    $this->get(EmployeeResource::getUrl('edit', ['record' => $employee]).'?relation=1')
        ->assertOk()->assertSee('Timesheets')->assertSee('Leave requests');
});

it('the Timesheets and Leave requests tabs are hidden from people without the matching permission', function (): void {
    $employee = userWithRole(RoleName::Employee);
    $viewer = userWithRole(RoleName::Employee);
    $page = EditEmployee::class;

    $this->actingAs($viewer);
    expect(TimesheetsRelationManager::canViewForRecord($employee, $page))->toBeFalse()
        ->and(LeaveRequestsRelationManager::canViewForRecord($employee, $page))->toBeFalse();
});

it('employee metadata rows can be reordered', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    $employee = userWithRole(RoleName::Employee);

    Livewire::test(EditEmployee::class, ['record' => $employee->getKey()])
        ->assertFormFieldExists('profile.metadata', fn (KeyValue $field): bool => $field->isReorderable());
});

it('D-56 an admin sees every leave request, including on the employee page tab', function (): void {
    $admin = userWithRole(RoleName::Admin);
    $employee = userWithRole(RoleName::Employee); // not managed by the admin
    $leave = LeaveRequest::factory()->create(['user_id' => $employee->id]);

    expect($admin->can(Permissions::LEAVE_REQUESTS_VIEW_ANY))->toBeTrue()
        ->and($admin->can('view', $leave))->toBeTrue();

    $this->actingAs($admin);
    expect(LeaveRequestsRelationManager::canViewForRecord($employee, EditEmployee::class))->toBeTrue();
    Livewire::test(ManageLeaveRequests::class)->assertCanSeeTableRecords([$leave]);
});
