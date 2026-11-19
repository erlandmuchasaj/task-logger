<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;

/**
 * A script for `assertScript()` that waits up to five seconds for a JavaScript condition to hold. Livewire
 * answers after a round trip, and `assertSee()` and `assertDontSee()` look once without waiting.
 */
function untilScript(string $condition): string
{
    return "async () => { for (let i = 0; i < 50; i++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; }";
}

/** A script condition: the element with this id is on the page. */
function hasId(string $id): string
{
    return "document.getElementById('{$id}') !== null";
}

/** The Save button of a custom page. The user menu holds a submit button too, so the bare selector is unsafe. */
const SAVE_BUTTON = 'button.fi-btn[type=submit]';

it('NFR-01 signs in through the login form and lands on the dashboard', function (): void {
    $user = userWithRole(RoleName::Employee);

    signInThroughBrowser($user)
        ->assertPathIs('/app')
        ->assertNoJavaScriptErrors();
})->group('browser', 'NFR-01');

it('NFR-01 refuses a wrong password and stays on the login page', function (): void {
    $user = userWithRole(RoleName::Employee);

    signInThroughBrowser($user, 'not-the-password')
        ->assertSee('These credentials do not match our records.')
        ->assertPathIs('/app/login');
})->group('browser', 'NFR-01');

it('D-20 does not let a banned user sign in', function (): void {
    $user = userWithRole(RoleName::Employee);
    $user->ban();

    signInThroughBrowser($user)
        ->assertSee('These credentials do not match our records.')
        ->assertPathIs('/app/login');
})->group('browser', 'D-20');

it('D-53 sends a temporary password to the change page, refuses a weak one and accepts a strong one', function (): void {
    $user = User::factory()->withRole(RoleName::Employee)->create(['must_change_password' => true]);

    signInThroughBrowser($user)
        ->assertPathIs('/app/change-password')
        ->assertSee('Choose your password')
        ->type('[id="form.password"]', 'Short1')
        ->type('[id="form.passwordConfirmation"]', 'Short1')
        ->press(SAVE_BUTTON)
        ->assertScript(untilScript("document.body.innerText.includes('at least 12 characters')"))
        ->assertPathIs('/app/change-password');

    expect($user->refresh()->must_change_password)->toBeTrue();

    visit('/app/change-password')
        ->type('[id="form.password"]', 'Correct-Horse-Battery-9')
        ->type('[id="form.passwordConfirmation"]', 'Correct-Horse-Battery-9')
        ->press(SAVE_BUTTON)
        ->assertScript(untilScript("location.pathname === '/app'"))
        ->assertPathIs('/app')
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->must_change_password)->toBeFalse();
})->group('browser', 'D-53', 'D-50');

it('D-53 blocks every other page while the password is temporary', function (): void {
    $user = User::factory()->withRole(RoleName::Employee)->create(['must_change_password' => true]);

    $this->actingAs($user);

    visit('/app/my-day')
        ->assertPathIs('/app/change-password')
        ->assertSee('Choose your password');
})->group('browser', 'D-53');

it('D-26 reveals the two-factor settings in order and hides them again', function (): void {
    $this->actingAs(userWithRole(RoleName::Superadmin));

    visit('/app/two-factor-settings')
        ->assertSee('Enable two-factor authentication')
        ->click('[id="form.two_factor_enabled"]')
        ->assertScript(untilScript('! '.hasId('form.two_factor_required')))
        ->assertNotPresent('[id="form.two_factor_grace_days"]')
        ->click('[id="form.two_factor_enabled"]')
        ->assertScript(untilScript(hasId('form.two_factor_required')))
        ->assertNotPresent('[id="form.two_factor_grace_days"]')
        ->click('[id="form.two_factor_required"]')
        ->assertScript(untilScript(hasId('form.two_factor_grace_days')))
        ->click('[id="form.two_factor_required"]')
        ->assertScript(untilScript('! '.hasId('form.two_factor_grace_days')))
        ->assertPresent('[id="form.two_factor_required"]');
})->group('browser', 'D-26');

it('NFR-03 refuses the two-factor settings to an HR user', function (): void {
    $this->actingAs(userWithRole(RoleName::Hr));

    visit('/app/two-factor-settings')
        ->assertSee('403')
        ->assertDontSee('Enable two-factor authentication');
})->group('browser', 'NFR-03', 'D-26');
