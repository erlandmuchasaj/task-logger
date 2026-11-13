<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Customer;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Settings\SecuritySettings;
use App\Domain\People\Actions\CreateEmployee;
use App\Domain\People\Actions\ResetEmployeePassword;
use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Support\LockedGlobalSearchProvider;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Notification::fake();
    Filament::setCurrentPanel('app');
});

function temporaryPasswordUser(): User
{
    $user = User::factory()->withRole(RoleName::Employee)->create(['password' => 'Temporary-pass-1']);
    $user->forceFill(['must_change_password' => true])->saveQuietly();

    return $user;
}

it('D-53 a new account and an admin reset both carry a temporary password', function (): void {
    $actor = userWithRole(RoleName::Superadmin);
    $created = resolve(CreateEmployee::class)->handle($actor, employeeData());
    expect($created->fresh()->must_change_password)->toBeTrue();

    $existing = userWithRole(RoleName::Employee);
    expect($existing->must_change_password)->toBeFalse();
    resolve(ResetEmployeePassword::class)->handle($actor, $existing);
    expect($existing->fresh()->must_change_password)->toBeTrue();
});

it('D-53 every panel page sends a temporary-password user to the change page, but sign-out still works', function (): void {
    $this->actingAs(temporaryPasswordUser());

    $this->get('/app')->assertRedirect(ChangePassword::getUrl());
    $this->get('/app/labels')->assertRedirect(ChangePassword::getUrl());
    $this->get(route('filament.app.auth.profile'))->assertRedirect(ChangePassword::getUrl());
    $this->get(ChangePassword::getUrl())->assertOk()->assertSee('You signed in with a temporary password');
    $this->post(Filament::getLogoutUrl())->assertRedirect();
    $this->assertGuest();
});

it('D-53 choosing a new password clears the flag, retires the temporary one and is audited without the value', function (): void {
    $user = temporaryPasswordUser();
    $this->actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm(['password' => 'My-own-password-9', 'passwordConfirmation' => 'My-own-password-9'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('My-own-password-9', $user->password))->toBeTrue()
        ->and(Hash::check('Temporary-pass-1', $user->password))->toBeFalse();
    $audit = Activity::query()->where('event', 'password_changed')->where('subject_id', $user->id)->sole();
    expect(json_encode($audit->toArray()))->not->toContain('My-own-password-9')->not->toContain('$2y$');

    $this->get('/app')->assertOk();
});

it('D-53 the new password must meet the policy, match its confirmation and differ from the temporary one', function (string $password, string $confirmation): void {
    $this->actingAs(temporaryPasswordUser());

    Livewire::test(ChangePassword::class)
        ->fillForm(['password' => $password, 'passwordConfirmation' => $confirmation])
        ->call('save')
        ->assertHasFormErrors(['password']);
})->with([
    'too weak' => ['short', 'short'],
    'mismatch' => ['My-own-password-9', 'Other-password-9'],
    'same as temporary' => ['Temporary-pass-1', 'Temporary-pass-1'],
]);

it('D-53 a password chosen through the reset link or on the profile also clears the flag', function (): void {
    $viaLink = temporaryPasswordUser();
    event(new PasswordReset($viaLink));
    expect($viaLink->fresh()->must_change_password)->toBeFalse();

    $viaProfile = temporaryPasswordUser();
    $viaProfile->forceFill(['must_change_password' => false])->saveQuietly(); // let the profile page open
    $this->actingAs($viaProfile);
    $viaProfile->forceFill(['must_change_password' => true])->saveQuietly();
    Livewire::test(EditProfile::class)
        ->fillForm(['password' => 'My-own-password-9', 'passwordConfirmation' => 'My-own-password-9', 'currentPassword' => 'Temporary-pass-1'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($viaProfile->fresh()->must_change_password)->toBeFalse();
});

it('D-53 the password comes before 2FA: a user who must do both is sent to the password page first', function (): void {
    $user = temporaryPasswordUser();
    $settings = resolve(SecuritySettings::class);
    $settings->two_factor_required = true;
    $settings->two_factor_required_since = now()->subDays(60)->toIso8601String();
    $settings->save();
    $this->travel(30)->days();

    $this->actingAs($user)->get('/app')->assertRedirect(ChangePassword::getUrl());
});

it('D-53 an administrator impersonating a new hire is not blocked by the forced change', function (): void {
    $user = temporaryPasswordUser();
    $this->actingAs($user);
    $this->mock(ImpersonateManager::class)->shouldReceive('isImpersonating')->andReturnTrue();

    $this->get('/app')->assertSuccessful();
});

it('D-53 the change page is closed to anyone without a temporary password', function (): void {
    $this->actingAs(userWithRole(RoleName::Employee))->get(ChangePassword::getUrl())->assertForbidden();
});

it('D-53 a page opened before the reset cannot keep running actions', function (): void {
    $user = userWithRole(RoleName::Superadmin);
    $this->actingAs($user);
    $labels = $this->get('/app/labels')->assertOk()->getContent();
    $user->forceFill(['must_change_password' => true])->saveQuietly();

    $response = livewireUpdate($labels, 'ManageLabels', '$refresh');
    expect($response->isRedirect() || $response->status() >= 400)->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/app/change-password');
});

it('D-53 global search finds nothing while a temporary password is in use', function (): void {
    $user = userWithRole(RoleName::Superadmin);
    Customer::factory()->create(['company_name' => 'Searchable Ltd']);
    $this->actingAs($user);
    $provider = resolve(LockedGlobalSearchProvider::class);

    expect($provider->getResults('Searchable')->getCategories())->not->toBeEmpty();

    $user->forceFill(['must_change_password' => true])->saveQuietly();

    expect($provider->getResults('Searchable')->getCategories())->toBeEmpty();
});
