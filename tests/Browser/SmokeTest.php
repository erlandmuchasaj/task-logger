<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;

/*
 * Browser smoke tests: a real browser opens every screen each role may use, and no JavaScript or console error appears.
 * `actingAs()` before `visit()` carries the session into the browser, so only the login form itself is typed by hand.
 */

// Screens every role holds (D-09: every role inherits the employee baseline).
const SMOKE_EVERYONE = [
    '/app/my-day',
    '/app/logs',
    '/app/logs?tab=calendar',
    '/app/logs/create',
    '/app/clients',
    '/app/projects',
    '/app/tasks',
    '/app/exports',
    '/app/leave-requests',
    '/app/leave-requests?tab=calendar',
    '/app/my-leave',
    '/app/bank-holidays',
];

it('NFR-03 every role opens each screen it may use without a JavaScript or console error', function (RoleName $role, array $urls): void {
    $this->actingAs(userWithRole($role));

    visit([...SMOKE_EVERYONE, ...$urls])->assertNoSmoke();
})->with([
    'superadmin' => [RoleName::Superadmin, [
        '/app/employees', '/app/employees/create', '/app/offboardings', '/app/performances',
        '/app/estimate-vs-logged', '/app/expenses', '/app/timesheet-locks', '/app/activity-log',
        '/app/manage-settings', '/app/two-factor-settings',
        '/app/countries', '/app/departments', '/app/technologies', '/app/labels', '/app/leave-types', '/app/payment-plans',
    ]],
    'admin' => [RoleName::Admin, ['/app/employees', '/app/offboardings', '/app/estimate-vs-logged', '/app/manage-settings']],
    'hr' => [RoleName::Hr, ['/app/employees', '/app/offboardings', '/app/performances', '/app/manage-settings']],
    'assistant' => [RoleName::Assistant, ['/app/estimate-vs-logged', '/app/countries', '/app/departments', '/app/technologies']],
    'finance' => [RoleName::Finance, ['/app/expenses']],
    'employee' => [RoleName::Employee, []],
])->group('browser', 'NFR-03');

it('NFR-03 the main employee screens work in dark mode', function (): void {
    $this->actingAs(userWithRole(RoleName::Employee));

    visit(['/app/my-day', '/app/logs?tab=calendar', '/app/leave-requests?tab=calendar'])
        ->inDarkMode()
        ->assertNoSmoke();
})->group('browser', 'NFR-03');

it('NFR-03 the main employee screens work at phone width without a sideways page scroll', function (string $url): void {
    $this->actingAs(userWithRole(RoleName::Employee));

    $page = visit($url)->on()->mobile()->assertNoSmoke();

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
})->with(['/app/my-day', '/app/logs?tab=calendar', '/app/leave-requests?tab=calendar'])->group('browser', 'NFR-03');

it('FR-02 the sidebar shows each role only the groups and screens it holds', function (RoleName $role, array $sees, array $hidden): void {
    $this->actingAs(userWithRole($role));

    $page = visit('/app/my-day')->assertSee('My Day');

    /** @var array<int, string> $labels */
    $labels = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('.fi-sidebar-nav .fi-sidebar-group-label, .fi-sidebar-nav .fi-sidebar-item-label'))
            .map((element) => element.textContent.trim())
        JS);

    foreach ($sees as $text) {
        expect($labels)->toContain($text);
    }

    foreach ($hidden as $text) {
        expect($labels)->not->toContain($text);
    }
})->with([
    // D-79: every role holds hierarchy.view, so the People group shows for all of them, with Hierarchy but not Employees.
    'employee' => [RoleName::Employee, ['Work', 'Leave', 'People', 'Hierarchy'], ['Employees', 'Two-factor authentication', 'Expenses', 'Master data']],
    'hr' => [RoleName::Hr, ['People', 'Employees'], ['Two-factor authentication', 'Expenses', 'Activity log']],
    'finance' => [RoleName::Finance, ['Expenses', 'Hierarchy'], ['Employees', 'Two-factor authentication']],
    'superadmin' => [RoleName::Superadmin, ['People', 'Employees', 'Two-factor authentication', 'Expenses'], []],
])->group('browser', 'FR-02');

it('NFR-03 an employee is refused the People and Administration screens', function (string $url): void {
    $this->actingAs(userWithRole(RoleName::Employee));

    visit($url)->assertSee('403');
})->with(['/app/employees', '/app/two-factor-settings', '/app/activity-log', '/app/manage-settings'])->group('browser', 'NFR-03');
