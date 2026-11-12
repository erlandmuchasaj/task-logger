<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\StartImpersonation;
use App\Domain\Identity\Actions\StopImpersonation;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\ActivityLog;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Label;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Livewire;

/** The session keys lab404/laravel-impersonate sets while someone acts as another user. */
function impersonationSession(User $impersonator): array
{
    return ['impersonated_by' => $impersonator->id, 'impersonator_guard' => 'web', 'impersonator_guard_using' => null];
}

it('D-47 only the superadmin holds employees.impersonate', function (RoleName $role, bool $allowed) {
    $target = userWithRole(RoleName::Employee);
    $actor = userWithRole($role);

    if ($allowed) {
        app(StartImpersonation::class)->handle($actor, $target);
        expect(auth()->id())->toBe($target->id)->and(app(ImpersonateManager::class)->isImpersonating())->toBeTrue();
    } else {
        expect(fn () => app(StartImpersonation::class)->handle($actor, $target))->toThrow(AuthorizationException::class)
            ->and(app(ImpersonateManager::class)->isImpersonating())->toBeFalse();
    }
})->with([
    [RoleName::Superadmin, true], [RoleName::Admin, false], [RoleName::Hr, false], [RoleName::Assistant, false], [RoleName::Employee, false],
])->group('D-47', 'FR-04');

it('D-47 you cannot impersonate yourself, a deactivated or anonymised account, another root user, or twice', function () {
    $root = userWithRole(RoleName::Superadmin);
    $banned = userWithRole(RoleName::Employee);
    $banned->ban();
    $banned->refresh();
    $anonymised = User::factory()->withRole(RoleName::Employee)->create(['anonymized_at' => now()]);
    $otherRoot = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);

    foreach ([$root, $banned, $anonymised, $otherRoot] as $target) {
        expect(fn () => app(StartImpersonation::class)->handle($root, $target))->toThrow(ValidationException::class);
    }

    expect(app(ImpersonateManager::class)->isImpersonating())->toBeFalse();

    app(StartImpersonation::class)->handle($root, $employee);

    expect(fn () => app(StartImpersonation::class)->handle($root, userWithRole(RoleName::Hr)))->toThrow(ValidationException::class, 'Stop the current');
})->group('D-47');

it('D-47 D-38 starting and stopping are audited, and writes in between carry the impersonator', function () {
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);

    app(StartImpersonation::class)->handle($root, $employee);

    $started = ActivityLog::where('event', 'impersonation_started')->sole();
    expect($started)->causer_id->toBe($root->id)->subject_id->toBe($employee->id);

    asRequest(impersonatedBy: $root->id);
    activity()->causedBy($employee)->performedOn($employee)->event('updated')->log('updated');
    expect(latestActivity('updated'))->causer_id->toBe($employee->id)->impersonator_id->toBe($root->id);

    expect(app(StopImpersonation::class)->handle())->toBeTrue()
        ->and(auth()->id())->toBe($root->id)
        ->and(app(ImpersonateManager::class)->isImpersonating())->toBeFalse();

    expect(ActivityLog::where('event', 'impersonation_ended')->sole())->causer_id->toBe($root->id)->subject_id->toBe($employee->id)
        ->and(app(StopImpersonation::class)->handle())->toBeFalse();
})->group('D-47', 'D-38');

it('D-47 the superadmin impersonates from the Employees list, and the button is hidden where it must be', function () {
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);
    $otherRoot = userWithRole(RoleName::Superadmin);
    $banned = userWithRole(RoleName::Employee);
    $banned->ban();

    $this->actingAs($root);

    Livewire::test(ListEmployees::class)
        ->assertTableActionVisible('impersonate', $employee)
        ->assertTableActionHidden('impersonate', $root)
        ->assertTableActionHidden('impersonate', $otherRoot)
        ->assertTableActionHidden('impersonate', $banned)
        ->callTableAction('impersonate', $employee)
        ->assertRedirect('/app');

    expect(auth()->id())->toBe($employee->id);

    $this->actingAs(userWithRole(RoleName::Admin));
    Livewire::test(ListEmployees::class)->assertTableActionHidden('impersonate', $employee);
})->group('D-47');

it('D-47 the banner and the stop button appear only while impersonating, and stopping returns to the superadmin', function () {
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);

    $this->actingAs($employee)->get('/app')->assertOk()->assertDontSee('Stop impersonating');

    $this->actingAs($employee)->withSession(impersonationSession($root))
        ->get('/app')->assertOk()->assertSee('Stop impersonating')->assertSee($employee->getFilamentName());

    $this->actingAs($employee)->withSession(impersonationSession($root))
        ->post(route('impersonation.leave'))->assertRedirect('/app');

    $this->assertAuthenticatedAs($root);
})->group('D-47');

it('D-47 stopping when not impersonating is forbidden, and starting is not reachable by URL', function () {
    $this->actingAs(userWithRole(RoleName::Superadmin))->post(route('impersonation.leave'))->assertForbidden();
    $this->actingAs(userWithRole(RoleName::Superadmin))->get('/impersonate/take/1')->assertNotFound();
})->group('D-47');

it('D-47 while impersonating, the profile page (password and two-factor) is closed', function () {
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);

    $this->actingAs($employee)->get('/app/profile')->assertOk();
    $this->actingAs($employee)->withSession(impersonationSession($root))->get('/app/profile')->assertForbidden();
})->group('D-47');

it('D-47 the impersonated user\'s own rights apply: acting as an employee grants no admin access', function () {
    $root = userWithRole(RoleName::Superadmin);
    $employee = userWithRole(RoleName::Employee);

    app(StartImpersonation::class)->handle($root, $employee);

    expect(auth()->user()->can('create', Label::class))->toBeFalse()->and(auth()->user()->can('impersonate', $root))->toBeFalse();
})->group('D-47', 'FR-02');
