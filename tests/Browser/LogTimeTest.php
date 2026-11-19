<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Work\Models\Log;
use App\Domain\Work\Models\Task;
use App\Domain\Work\Models\TimesheetLock;
use Pest\Browser\Api\AwaitableWebpage;

/*
 * Wednesday 11 March 2026, mid-day UTC. A person without a country lives in UTC (BR-34), so "today" is the 11th
 * and the calendar labels below do not depend on when the suite runs.
 */
beforeEach(fn () => $this->travelTo('2026-03-11 10:00:00'));

/** Opens the Logs calendar as someone and waits until it has rendered. */
function openLogsCalendar(User $user, string $query = ''): AwaitableWebpage
{
    test()->actingAs($user);

    return visit('/app/logs?tab=calendar'.$query)->assertSee('Month total');
}

/** Clicks a calendar day and waits for the log modal. The button is found by its label: "Log time" is also the modal's heading. */
function openLogModal(AwaitableWebpage $page, string $dayLabel): AwaitableWebpage
{
    return $page->click('[aria-label="Log time on '.$dayLabel.'"]')->assertSee('Log & add another');
}

/**
 * Same, for a day that already has entries: an entry is a link laid over the day button, so a real click on the
 * middle of the cell would open that entry. The click is sent to the button itself.
 */
function openLogModalOnBusyDay(AwaitableWebpage $page, string $dayLabel): AwaitableWebpage
{
    $page->script('document.querySelector(\'[aria-label="Log time on '.$dayLabel.'"]\').click()');

    return $page->assertSee('Log & add another');
}

/** Picks a task in the modal's select (a Filament select is custom, not a native one) and types the time. */
function fillLogModal(AwaitableWebpage $page, ?int $taskId, int $hours, int $minutes): AwaitableWebpage
{
    if ($taskId !== null) {
        $page->click('[id="mountedActionSchema0.task_id"]')->click('.fi-select-input-option[data-value="'.$taskId.'"]');
    }

    return $page->fill('[id="mountedActionSchema0.hours"]', (string) $hours)->fill('[id="mountedActionSchema0.minutes"]', (string) $minutes);
}

it('D-11 logs time on a past day from the calendar, with a notification, on the day and in the database', function (): void {
    ['employee' => $employee, 'task' => $task] = loggingSetup();

    $page = openLogModal(openLogsCalendar($employee), '4 March 2026');
    fillLogModal($page, $task->id, 2, 30)->click('.fi-modal-footer button[type=submit]');

    $page->assertSee('Time logged: 2:30')
        ->assertSeeIn('[wire\:key="calendar-day-2026-03-04"] a', '2:30')
        ->assertDontSee('Log & add another');

    $log = Log::query()->where('user_id', $employee->id)->sole();
    expect($log->duration_minutes)->toBe(150)
        ->and($log->date->toDateString())->toBe('2026-03-04')
        ->and($log->task_id)->toBe($task->id);
})->group('browser', 'D-11');

it('D-11 keeps the modal open with an empty form after "Log & add another", and saves the first log', function (): void {
    ['employee' => $employee, 'task' => $task] = loggingSetup();

    $page = openLogModal(openLogsCalendar($employee), '4 March 2026');
    fillLogModal($page, $task->id, 1, 15)->click('.fi-modal-footer button:has-text("Log & add another")');

    $page->assertSee('Time logged: 1:15')
        ->assertSeeIn('[id="mountedActionSchema0.task_id"]', 'Select an option')
        ->assertValue('[id="mountedActionSchema0.hours"]', '0')
        ->assertValue('[id="mountedActionSchema0.minutes"]', '0')
        ->assertSee('Log & add another');

    expect(Log::query()->where('user_id', $employee->id)->sole()->duration_minutes)->toBe(75);

    // The modal is still usable: a second entry on the same day.
    fillLogModal($page, $task->id, 0, 45)->click('.fi-modal-footer button:has-text("Log & add another")');
    $page->assertSee('Time logged: 0:45');

    expect(Log::query()->where('user_id', $employee->id)->orderBy('id')->pluck('duration_minutes')->all())->toBe([75, 45]);
})->group('browser', 'D-11');

it('D-39 offers no button on a future day, and BR-07 none on a day of a locked month, for an employee', function (): void {
    ['employee' => $employee] = loggingSetup();
    TimesheetLock::query()->create(['month' => '2026-02-01', 'locked_by' => userWithRole(RoleName::Superadmin)->id, 'locked_at' => now()]);

    // Today and yesterday can be logged on; tomorrow cannot.
    openLogsCalendar($employee)
        ->assertPresent('[aria-label="Log time on 11 March 2026"]')
        ->assertPresent('[aria-label="Log time on 10 March 2026"]')
        ->assertMissing('[aria-label="Log time on 12 March 2026"]')
        ->assertMissing('[aria-label="Log time on 31 March 2026"]');

    // A locked month shows its banner and no day button at all.
    openLogsCalendar($employee, '&month=2026-02')
        ->assertSee('This month is locked, so time can')
        ->assertMissing('[aria-label="Log time on 10 February 2026"]');

    expect(Log::query()->count())->toBe(0);
})->group('browser', 'D-39', 'BR-07');

it('BR-04 refuses a log of 0 hours and 0 minutes under the minutes field and saves nothing', function (): void {
    ['employee' => $employee, 'task' => $task] = loggingSetup();

    $page = openLogModal(openLogsCalendar($employee), '4 March 2026');
    fillLogModal($page, $task->id, 0, 0)->click('.fi-modal-footer button[type=submit]');

    $page->assertSeeIn('[wire\:partial="schema-component::mountedActionSchema0.minutes"]', 'The time must be greater than zero.')
        ->assertSee('Log & add another');

    expect(Log::query()->count())->toBe(0);
})->group('browser', 'BR-04');

it('BR-04 refuses more than 24 hours on one day, with the hours already logged, and saves nothing', function (): void {
    ['employee' => $employee, 'task' => $task, 'project' => $project, 'technology' => $technology] = loggingSetup();
    Log::factory()->create([
        'user_id' => $employee->id, 'project_id' => $project->id, 'technology_id' => $technology->id, 'task_id' => $task->id,
        'date' => '2026-03-10', 'duration_minutes' => 23 * 60,
    ]);

    $page = openLogModalOnBusyDay(openLogsCalendar($employee), '10 March 2026');
    fillLogModal($page, $task->id, 2, 0)->click('.fi-modal-footer button[type=submit]');

    $page->assertSeeIn('[wire\:partial="schema-component::mountedActionSchema0.minutes"]', 'more than 24 hours logged on Mar 10, 2026 (23h 0m already logged)')
        ->assertSee('Log & add another');

    expect(Log::query()->where('user_id', $employee->id)->sole()->duration_minutes)->toBe(23 * 60);
})->group('browser', 'BR-04');

it('BR-08 logs from My Day with a pinned task, shows the row and the day total, and deletes it after a confirmation', function (): void {
    ['employee' => $employee, 'task' => $task] = loggingSetup();
    $task->update(['force_to_log' => true]);
    $this->actingAs($employee);

    $page = visit('/app/my-day')->assertSee('Pinned tasks')->assertSee('Logged on this day: 0h 0m of 24h');

    // The pinned button fills the task select of the form.
    $page->click('[wire\:key="pinned-'.$task->id.'"]')
        ->assertSeeIn('[id="form.task_id"]', $task->name);
    $page->fill('[id="form.hours"]', '1')->fill('[id="form.minutes"]', '20')
        ->click('button[type=submit]:has-text("Log time")');

    $page->assertSee('Time logged: 1:20')
        ->assertSee('Logged on this day: 1h 20m of 24h')
        ->assertSeeIn('.fi-ta', '1:20');

    $log = Log::query()->where('user_id', $employee->id)->sole();
    expect($log->duration_minutes)->toBe(80)->and($log->task_id)->toBe($task->id);

    // Delete asks first: cancelling keeps the log, confirming removes it.
    $page->click('.fi-ta-row button[aria-label="Actions"]')->click('button.fi-dropdown-list-item:has-text("Delete")');
    $page->assertSee('Are you sure you would like to do this?')->click('.fi-modal-footer button:has-text("Cancel")');
    expect(Log::query()->count())->toBe(1);

    $page->click('.fi-ta-row button[aria-label="Actions"]')->click('button.fi-dropdown-list-item:has-text("Delete")')
        ->click('.fi-modal-footer button:has-text("Confirm")');

    $page->assertSee('Log deleted')->assertSee('Logged on this day: 0h 0m of 24h');
    expect(Log::query()->count())->toBe(0);
})->group('browser', 'BR-08');

it("BR-02 gives an employee only their own tasks in the Task select, never a colleague's", function (): void {
    ['task' => $colleaguesTask, 'project' => $project, 'technology' => $technology] = loggingSetup();
    $employee = userWithRole(RoleName::Employee);
    $project->memberships()->create(['user_id' => $employee->id]);
    $ownTask = Task::factory()->create(['project_id' => $project->id, 'technology_id' => $technology->id, 'assignee_id' => $employee->id]);

    $page = openLogModal(openLogsCalendar($employee), '4 March 2026');
    $page->click('[id="mountedActionSchema0.task_id"]')
        ->assertPresent('.fi-select-input-option[data-value="'.$ownTask->id.'"]')
        ->assertNotPresent('.fi-select-input-option[data-value="'.$colleaguesTask->id.'"]');

    // The same on My Day.
    $this->actingAs($employee);
    visit('/app/my-day')->assertSee('Logged on this day')
        ->click('[id="form.task_id"]')
        ->assertPresent('.fi-select-input-option[data-value="'.$ownTask->id.'"]')
        ->assertNotPresent('.fi-select-input-option[data-value="'.$colleaguesTask->id.'"]');
})->group('browser', 'BR-02');
