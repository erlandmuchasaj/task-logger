<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Settings\SecuritySettings;
use App\Domain\Identity\Support\TwoFactorPolicy;
use App\Filament\Pages\TwoFactorSettings;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function security(array $values): void
{
    $settings = app(SecuritySettings::class);
    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }
    $settings->save();
}

function enrol(User $user): void
{
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
}

it('D-26 defaults: 2FA available, not required, two weeks grace', function () {
    $settings = app(SecuritySettings::class);
    $policy = app(TwoFactorPolicy::class);

    expect($settings->two_factor_enabled)->toBeTrue()
        ->and($settings->two_factor_required)->toBeFalse()
        ->and($settings->two_factor_grace_days)->toBe(14)
        ->and($policy->isRequired())->toBeFalse()
        ->and($policy->mustSetUpNow(userWithRole(RoleName::Employee)))->toBeFalse();
});

it('D-26 users have a grace period once 2FA is required, then must enrol', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);
    $policy = app(TwoFactorPolicy::class);

    expect($policy->isInGracePeriod($user))->toBeTrue()
        ->and($policy->mustSetUpNow($user))->toBeFalse()
        ->and($policy->graceEndsAt($user)->toDateString())->toBe(now()->addDays(14)->toDateString());

    $this->travel(13)->days();
    expect($policy->mustSetUpNow($user))->toBeFalse();

    $this->travel(2)->days();
    expect($policy->isInGracePeriod($user))->toBeFalse()
        ->and($policy->mustSetUpNow($user))->toBeTrue();
});

it('D-26 a user who has enrolled is never forced', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);
    enrol($user);

    $this->travel(60)->days();

    expect(app(TwoFactorPolicy::class)->mustSetUpNow($user->fresh()))->toBeFalse();
});

it('D-26 the grace period is configurable', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String(), 'two_factor_grace_days' => 7]);
    $policy = app(TwoFactorPolicy::class);

    $this->travel(8)->days();
    expect($policy->mustSetUpNow($user))->toBeTrue();

    security(['two_factor_grace_days' => 30]); // extending applies immediately
    expect($policy->mustSetUpNow($user))->toBeFalse();
});

it('D-26 [ASSUMED] a user created after the requirement gets their own full grace period', function () {
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->subDays(30)->toIso8601String()]);
    $newHire = userWithRole(RoleName::Employee);
    $policy = app(TwoFactorPolicy::class);

    expect($policy->isInGracePeriod($newHire))->toBeTrue()
        ->and($policy->graceEndsAt($newHire)->toDateString())->toBe(now()->addDays(14)->toDateString());
});

it('D-26 switching 2FA off globally removes the requirement and the challenge providers', function () {
    $user = userWithRole(RoleName::Employee);
    enrol($user);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);

    expect(Filament::getPanel('app')->getMultiFactorAuthenticationProviders())->toHaveKey('app')
        ->and(MultiFactorChallenge::make()->hasEnabledProviders($user->fresh()))->toBeTrue();

    security(['two_factor_enabled' => false]);
    $this->travel(60)->days();
    $policy = app(TwoFactorPolicy::class);

    expect($policy->isRequired())->toBeFalse()
        ->and($policy->mustSetUpNow(userWithRole(RoleName::Hr)))->toBeFalse()
        ->and(Filament::getPanel('app')->getMultiFactorAuthenticationProviders())->toBe([])
        ->and(MultiFactorChallenge::make()->hasEnabledProviders($user->fresh()))->toBeFalse();
});

it('D-26 after the grace period the panel sends a user without 2FA to their profile', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);
    $this->actingAs($user);

    $this->get('/app')->assertOk(); // inside grace: banner, no redirect

    $this->travel(15)->days();
    $this->get('/app')->assertRedirect(route('filament.app.auth.profile'));
    $this->get('/app/labels')->assertRedirect(route('filament.app.auth.profile'));
    $this->get(route('filament.app.auth.profile'))->assertOk();
});

it('D-26 an enrolled user is not redirected after the grace period', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);
    enrol($user);
    $this->travel(30)->days();

    $this->actingAs($user->fresh())->get('/app')->assertOk();
});

it('D-26 only the superadmin can open the two-factor settings', function (RoleName $role, int $status) {
    $this->actingAs(userWithRole($role))->get('/app/two-factor-settings')->assertStatus($status);
})->with([
    [RoleName::Superadmin, 200], [RoleName::Admin, 403], [RoleName::Hr, 403],
    [RoleName::Assistant, 403], [RoleName::Employee, 403],
]);

it('D-26 the superadmin requires 2FA; the grace period starts then, and the change is audited', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    Activity::query()->delete();

    Livewire::test(TwoFactorSettings::class)
        ->fillForm(['two_factor_enabled' => true, 'two_factor_required' => true, 'two_factor_grace_days' => 21])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SecuritySettings::class);
    expect($settings->two_factor_required)->toBeTrue()
        ->and($settings->two_factor_grace_days)->toBe(21)
        ->and($settings->two_factor_required_since)->not->toBeNull();

    $audit = Activity::where('log_name', 'security')->sole();
    expect($audit->attribute_changes['old']['two_factor_required'])->toBeFalse()
        ->and($audit->attribute_changes['attributes']['two_factor_required'])->toBeTrue();

    Livewire::test(TwoFactorSettings::class)
        ->fillForm(['two_factor_enabled' => true, 'two_factor_required' => false])
        ->call('save');

    expect(app(SecuritySettings::class)->two_factor_required_since)->toBeNull();
});

it('D-26 re-saving while already required keeps the original grace start', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    security(['two_factor_required' => true, 'two_factor_required_since' => '2026-01-01T00:00:00+00:00']);

    Livewire::test(TwoFactorSettings::class)
        ->fillForm(['two_factor_grace_days' => 10])
        ->call('save');

    expect(app(SecuritySettings::class)->two_factor_required_since)->toBe('2026-01-01T00:00:00+00:00');
});

it('D-26 turning 2FA off clears the requirement', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin));
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);

    Livewire::test(TwoFactorSettings::class)
        ->fillForm(['two_factor_enabled' => false])
        ->call('save');

    $settings = app(SecuritySettings::class);
    expect($settings->two_factor_enabled)->toBeFalse()
        ->and($settings->two_factor_required)->toBeFalse()
        ->and($settings->two_factor_required_since)->toBeNull();
});

it('D-26 the TOTP secret is encrypted at rest, hidden and never written to the audit log', function () {
    $user = userWithRole(RoleName::Employee);
    Activity::query()->delete();

    enrol($user);

    $raw = DB::table('users')->where('id', $user->id)->value('app_authentication_secret');
    expect($raw)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()->getAppAuthenticationSecret())->toBe('JBSWY3DPEHPK3PXP')
        ->and($user->fresh()->toArray())->not->toHaveKey('app_authentication_secret')
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and(Activity::where('event', 'two_factor_enabled')->count())->toBe(1)
        ->and(json_encode(Activity::all()->toArray()))->not->toContain('JBSWY3DPEHPK3PXP');
});

it('D-26 a user with 2FA is not signed in by password alone, and is when 2FA is switched off', function () {
    Filament::setCurrentPanel('app');
    $user = userWithRole(RoleName::Employee);
    enrol($user);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeFalse(); // waiting for the one-time code

    auth()->logout();
    security(['two_factor_enabled' => false]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    expect(auth()->check())->toBeTrue(); // globally off: no challenge
});

it('D-26 during the grace period every page shows a prominent banner with an "Enable 2FA now" link', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);

    $this->actingAs($user)->get('/app')->assertOk()
        ->assertSee('Two-factor authentication is required')
        ->assertSee('Enable 2FA now')
        ->assertSee(route('filament.app.auth.profile'), false);
});

it('D-26 signing in during the grace period greets the user with a persistent reminder', function () {
    Filament::setCurrentPanel('app');
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_required' => true, 'two_factor_required_since' => now()->toIso8601String()]);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertNotified('Two-factor authentication is required');

    $this->assertAuthenticatedAs($user);
});

it('D-26 when 2FA is optional, there is no banner and the profile offers the authenticator app', function () {
    $user = userWithRole(RoleName::Employee);
    security(['two_factor_enabled' => true, 'two_factor_required' => false]);

    $this->actingAs($user)->get('/app')->assertOk()->assertDontSee('Enable 2FA now');
    $this->get(route('filament.app.auth.profile'))->assertOk()->assertSee('Authenticator app');
});

it('D-26 the employee list shows the 2FA column and filter only while 2FA is switched on', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin));

    security(['two_factor_enabled' => true]);
    Livewire::test(ListEmployees::class)
        ->assertTableColumnVisible('two_factor')
        ->assertTableFilterVisible('two_factor');

    security(['two_factor_enabled' => false, 'two_factor_required' => false]);
    Livewire::test(ListEmployees::class)
        ->assertTableColumnHidden('two_factor')
        ->assertTableFilterHidden('two_factor');
});
