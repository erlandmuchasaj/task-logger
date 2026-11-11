<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\DeactivateEmployee;
use App\Domain\People\Actions\ReactivateEmployee;
use App\Domain\People\Actions\ResetTwoFactor;
use App\Domain\People\Actions\SendSetPasswordLink;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('D-20 the superadmin deactivates an employee: login is blocked, sessions end, history stays', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $employee->id, 'payload' => '', 'last_activity' => time()]);
    Activity::query()->delete();

    app(DeactivateEmployee::class)->handle($actor, $employee, 'left the company');

    $employee->refresh();
    expect($employee->isBanned())->toBeTrue()
        ->and($employee->canAccessPanel(Filament::getPanel('app')))->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $employee->id)->count())->toBe(0)
        ->and(User::find($employee->id))->not->toBeNull()
        ->and(Activity::where('event', 'deactivated')->where('subject_id', $employee->id)->sole()->causer_id)->toBe($actor->id);
});

it('D-20 a deactivated user cannot sign in', function () {
    Filament::setCurrentPanel('app');
    $employee = userWithRole(RoleName::Employee);
    app(DeactivateEmployee::class)->handle(userWithRole(RoleName::Superadmin), $employee);

    Livewire::test(Login::class)
        ->fillForm(['email' => $employee->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeFalse();
});

it('D-20 only the superadmin may deactivate or reactivate', function (RoleName $role) {
    $employee = userWithRole(RoleName::Employee);

    app(DeactivateEmployee::class)->handle(userWithRole($role), $employee);
})->throws(AuthorizationException::class)->with([RoleName::Admin, RoleName::Hr, RoleName::Assistant, RoleName::Employee]);

it('D-20 you cannot deactivate yourself or the last superadmin', function () {
    $only = userWithRole(RoleName::Superadmin);

    expect(fn () => app(DeactivateEmployee::class)->handle($only, $only))->toThrow(ValidationException::class);

    $other = userWithRole(RoleName::Superadmin);
    $admin = userWithRole(RoleName::Superadmin);
    app(DeactivateEmployee::class)->handle($admin, $other); // fine: two others remain
    app(DeactivateEmployee::class)->handle($admin, $only);  // fine: $admin remains

    expect(fn () => app(DeactivateEmployee::class)->handle($admin, $admin))->toThrow(ValidationException::class);
});

it('D-20 reactivation restores access and is audited', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    app(DeactivateEmployee::class)->handle($actor, $employee);

    app(ReactivateEmployee::class)->handle($actor, $employee->fresh());

    expect($employee->fresh()->isBanned())->toBeFalse()
        ->and(Activity::where('event', 'reactivated')->where('subject_id', $employee->id)->exists())->toBeTrue();
});

it('D-15 an anonymised account is never reactivated', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = User::factory()->create(['anonymized_at' => now()]);
    $employee->ban();

    app(ReactivateEmployee::class)->handle($actor, $employee->fresh());

    expect($employee->fresh()->isBanned())->toBeTrue();
});

it('ADR-0005 the superadmin resets a lost two-factor device; others cannot', function () {
    $actor = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    $employee->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $employee->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb']);

    expect(fn () => app(ResetTwoFactor::class)->handle(userWithRole(RoleName::Admin), $employee))->toThrow(AuthorizationException::class);

    app(ResetTwoFactor::class)->handle($actor, $employee);

    $employee->refresh();
    expect($employee->hasTwoFactorEnabled())->toBeFalse()
        ->and($employee->getAppAuthenticationRecoveryCodes())->toBeNull()
        ->and(Activity::where('event', 'two_factor_reset')->where('subject_id', $employee->id)->sole()->causer_id)->toBe($actor->id);
});

it('W-01 the set-password link can be sent again', function () {
    Notification::fake();
    $employee = userWithRole(RoleName::Employee);

    app(SendSetPasswordLink::class)->handle($employee);
    app(SendSetPasswordLink::class)->handle($employee);

    Notification::assertSentToTimes($employee, ResetPassword::class, 2);
});

it('W-01 the set-password link stays valid for 72 hours', function () {
    expect(config('auth.passwords.users.expire'))->toBe(4320);
});

it('D-14 a user cannot change their own name or email on the profile page', function () {
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

it('FR-01 the employee list actions follow permissions', function () {
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
