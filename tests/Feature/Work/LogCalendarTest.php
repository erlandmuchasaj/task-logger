<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\Work\Actions\LockMonth;
use App\Domain\Work\Models\Log;
use App\Filament\Resources\Logs\LogResource;
use App\Filament\Resources\Logs\Pages\ListLogs;
use App\Filament\Resources\Logs\Support\LogCalendar;
use Illuminate\Support\Facades\Date;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * June 2026 starts on a Monday: the 6th and 7th are a weekend, the 30th is a Tuesday. "Now" is Monday the 15th.
 */
beforeEach(fn () => $this->travelTo(Date::parse('2026-06-15 10:00:00')));

/**
 * An employee in Albania (Monday to Friday week) who can log on a task and directly, with the leave types.
 *
 * @return array<string, mixed>
 */
function calendarWorld(): array
{
    $world = leaveSetup();

    return [...$world, ...loggingSetup(employee: $world['employee'])];
}

/** @param  array<string, mixed>  $world */
function calendarLog(array $world, string $date, int $minutes = 60, array $overrides = []): Log
{
    return Log::factory()->create(array_merge([
        'user_id' => $world['employee']->id, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id,
        'task_id' => $world['task']->id, 'date' => $date, 'duration_minutes' => $minutes,
    ], $overrides));
}

/** @param  array<string, mixed>  $world */
function calendarLeave(array $world, string $type, string $from, ?string $to = null, LeaveStatus $status = LeaveStatus::Approved, DayPart $start = DayPart::Full, DayPart $end = DayPart::Full, ?User $user = null): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'user_id' => ($user ?? $world['employee'])->id, 'leave_type_id' => $world[$type]->id,
        'start_date' => $from, 'end_date' => $to ?? $from, 'start_day_part' => $start, 'end_day_part' => $end, 'status' => $status,
    ]);
}

function calendarPage(User $user, ?string $month = null): Testable
{
    test()->actingAs($user);

    return Livewire::test(ListLogs::class)->set('activeTab', 'calendar')->set('calendarMonth', $month);
}

/** @return array<string, mixed> */
function calendarDay(Testable $page, string $date): array
{
    foreach ($page->instance()->calendar()['weeks'] as $week) {
        foreach ($week as $day) {
            if ($day !== null && $day['date'] === $date) {
                return $day;
            }
        }
    }

    throw new RuntimeException("{$date} is not on the calendar.");
}

/** The warning shown in the open log modal, or null while it is hidden. */
function modalNotice(Testable $page): ?string
{
    $schema = $page->instance()->{$page->instance()->getMountedActionSchemaName()};
    $callout = $schema->getFlatComponents(withHidden: false)['dateNotice'] ?? null;

    return $callout === null ? null : (string) $callout->getDescription();
}

/** @return list<int> */
function calendarEntryIds(Testable $page, string $date): array
{
    return array_column(calendarDay($page, $date)['entries'], 'id');
}

it('opens on the table, and the tabs switch between the table and the calendar', function (): void {
    $world = calendarWorld();
    $mine = calendarLog($world, '2026-06-10', overrides: ['description' => 'Fix login redirect']);
    $this->actingAs($world['employee']);

    Livewire::test(ListLogs::class)
        ->assertSet('activeTab', 'table')
        ->assertCanSeeTableRecords([$mine])
        ->assertSee('Fix login redirect')
        ->assertDontSee('Month total')
        ->set('activeTab', 'calendar')
        ->assertSee('Month total')->assertSee('June 2026')
        ->assertDontSee('Fix login redirect')
        ->set('activeTab', 'table')
        ->assertSee('Fix login redirect')
        ->assertDontSee('Month total');

    // The tab and the month live in the URL, so a calendar link can be shared or reloaded.
    Livewire::withQueryParams(['tab' => 'calendar', 'month' => '2026-05'])->test(ListLogs::class)
        ->assertSee('May 2026')->assertDontSee('Fix login redirect');
    $this->get('/app/logs?tab=calendar')->assertOk()->assertSee('Month total');
    $this->get('/app/logs')->assertOk()->assertDontSee('Month total');
});

it('renders the action modals on both tabs, because the log modal is mounted from the calendar', function (): void {
    $world = calendarWorld();
    $this->actingAs($world['employee']);

    // Filament renders a page's modals inside its table when the page has one; the calendar tab hides the table.
    Livewire::test(ListLogs::class)->assertSeeHtml('wire:partial="action-modals"')
        ->set('activeTab', 'calendar')->assertSeeHtml('wire:partial="action-modals"')
        ->set('activeTab', 'table')->assertSeeHtml('wire:partial="action-modals"');
});

it('shows only your own logs of the chosen month, from its first day to its last', function (): void {
    $world = calendarWorld();
    $first = calendarLog($world, '2026-06-01', 30);
    $last = calendarLog($world, '2026-06-30', 90);
    calendarLog($world, '2026-05-31', 100);
    calendarLog($world, '2026-07-01', 100);
    Log::factory()->create([
        'user_id' => userWithRole(RoleName::Employee)->id, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id,
        'date' => '2026-06-10', 'duration_minutes' => 480,
    ]);

    $page = calendarPage($world['employee']);
    $calendar = $page->instance()->calendar();
    $cells = collect($calendar['weeks'])->flatten(1);

    expect(calendarEntryIds($page, '2026-06-01'))->toBe([$first->id])
        ->and(calendarEntryIds($page, '2026-06-30'))->toBe([$last->id])
        ->and(calendarEntryIds($page, '2026-06-10'))->toBe([])
        ->and($calendar['totalMinutes'])->toBe(120)->and($calendar['total'])->toBe('2:00')
        // Cells outside the month are empty: a Monday start has none before the 1st, a Tuesday end has five after the 30th.
        ->and($cells)->toHaveCount(35)->and($cells->filter()->count())->toBe(30)->and($cells->last())->toBeNull();

    // The neighbouring months show their own logs.
    expect($page->set('calendarMonth', '2026-05')->instance()->calendar()['totalMinutes'])->toBe(100);
});

it("shows only your own logs on the calendar even when you may see everyone's in the table", function (): void {
    $world = calendarWorld();
    $admin = userWithRole(RoleName::Admin);
    $mine = Log::factory()->create(['user_id' => $admin->id, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id, 'date' => '2026-06-10']);
    $theirs = calendarLog($world, '2026-06-10');
    $this->actingAs($admin);

    $page = Livewire::test(ListLogs::class)->assertCanSeeTableRecords([$mine, $theirs])->set('activeTab', 'calendar');

    expect(calendarEntryIds($page, '2026-06-10'))->toBe([$mine->id]);
});

it('adds up the day and the month, names task and direct logs, and folds a long day behind "+N more"', function (): void {
    $world = calendarWorld();
    calendarLog($world, '2026-06-12', 30);
    calendarLog($world, '2026-06-12', 45, ['task_id' => null]);
    calendarLog($world, '2026-06-12', 60);
    calendarLog($world, '2026-06-12', 90);
    calendarLog($world, '2026-06-09', 15);

    $page = calendarPage($world['employee']);
    $day = calendarDay($page, '2026-06-12');

    expect($day['totalMinutes'])->toBe(225)->and($day['total'])->toBe('3:45')
        ->and(array_column($day['entries'], 'label'))->toBe([
            'Website · '.$world['task']->name, 'Website (direct)', 'Website · '.$world['task']->name, 'Website · '.$world['task']->name,
        ])
        ->and($page->instance()->calendar()['total'])->toBe('4:00');

    $page->assertSee('3:45')->assertSee('+1 more');
});

it('still names the project and task of a log after either was deleted', function (): void {
    $world = calendarWorld();
    calendarLog($world, '2026-06-10');
    $world['task']->delete();
    $world['project']->delete();

    $page = calendarPage($world['employee']);

    expect(calendarDay($page, '2026-06-10')['entries'][0]['label'])->toBe('Website · '.$world['task']->name);
});

it("shows your country's bank holidays, also from a holiday that began the month before", function (): void {
    $world = calendarWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Long Break', 'start_date' => '2026-05-29', 'end_date' => '2026-06-02']);
    BankHoliday::factory()->create(['country_id' => Country::factory()->create()->id, 'name' => 'Elsewhere Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);

    $page = calendarPage($world['employee']);

    expect(calendarDay($page, '2026-06-12')['holidays'])->toBe(['Founders Day'])
        ->and(calendarDay($page, '2026-06-01')['holidays'])->toBe(['Long Break'])
        ->and(calendarDay($page, '2026-06-02')['holidays'])->toBe(['Long Break'])
        ->and(calendarDay($page, '2026-06-03')['holidays'])->toBe([]);

    $page->assertSee('Founders Day')->assertSee('Long Break')->assertDontSee('Elsewhere Day');
});

it('shows approved and taken leave with its type and half day, and nothing else', function (): void {
    $world = calendarWorld();
    $colleague = userWithRole(RoleName::Employee);
    calendarLeave($world, 'annual', '2026-06-08', '2026-06-09');
    calendarLeave($world, 'sick', '2026-06-11', status: LeaveStatus::Taken, start: DayPart::Am);
    calendarLeave($world, 'annual', '2026-06-29', '2026-06-30', start: DayPart::Pm, end: DayPart::Am);
    calendarLeave($world, 'annual', '2026-06-16', status: LeaveStatus::Pending);
    calendarLeave($world, 'annual', '2026-06-17', status: LeaveStatus::Rejected);
    calendarLeave($world, 'annual', '2026-06-18', status: LeaveStatus::Cancelled);
    calendarLeave($world, 'unpaid', '2026-06-19', user: $colleague);

    $page = calendarPage($world['employee']);

    expect(calendarDay($page, '2026-06-08')['leave'])->toBe(['Annual Leave'])
        ->and(calendarDay($page, '2026-06-09')['leave'])->toBe(['Annual Leave'])
        ->and(calendarDay($page, '2026-06-11')['leave'])->toBe(['Sick Leave (AM)'])
        ->and(calendarDay($page, '2026-06-29')['leave'])->toBe(['Annual Leave (PM)'])
        ->and(calendarDay($page, '2026-06-30')['leave'])->toBe(['Annual Leave (AM)'])
        ->and(calendarDay($page, '2026-06-16')['leave'])->toBe([])
        ->and(calendarDay($page, '2026-06-17')['leave'])->toBe([])
        ->and(calendarDay($page, '2026-06-18')['leave'])->toBe([])
        ->and(calendarDay($page, '2026-06-19')['leave'])->toBe([]);

    $page->assertSee('Sick Leave (AM)');
});

it('shows leave only on the days it is charged: not on a weekend or a bank holiday', function (): void {
    $world = calendarWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Midweek Day', 'start_date' => '2026-06-23', 'end_date' => '2026-06-23']);
    calendarLeave($world, 'annual', '2026-06-05', '2026-06-08');   // Friday to Monday
    calendarLeave($world, 'annual', '2026-06-22', '2026-06-24');   // Monday to Wednesday over a holiday

    $page = calendarPage($world['employee']);
    $leave = fn (string $date): array => calendarDay($page, $date)['leave'];

    expect($leave('2026-06-05'))->toBe(['Annual Leave'])
        ->and($leave('2026-06-06'))->toBe([])->and($leave('2026-06-07'))->toBe([])
        ->and($leave('2026-06-08'))->toBe(['Annual Leave'])
        ->and($leave('2026-06-22'))->toBe(['Annual Leave'])->and($leave('2026-06-23'))->toBe([])->and($leave('2026-06-24'))->toBe(['Annual Leave']);
});

it('marks a past working day with nothing logged, and nothing that is excused or not yet due', function (): void {
    $world = calendarWorld();
    $world['employee']->update(['start_date' => '2026-06-02']);
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Midweek Day', 'start_date' => '2026-06-11', 'end_date' => '2026-06-11']);
    calendarLog($world, '2026-06-08');
    calendarLeave($world, 'annual', '2026-06-10');
    calendarLeave($world, 'annual', '2026-06-09', start: DayPart::Pm);

    $page = calendarPage($world['employee']);
    $missing = fn (string $date): bool => calendarDay($page, $date)['isMissing'];

    expect($missing('2026-06-12'))->toBeTrue()       // a past working day, nothing logged
        ->and($missing('2026-06-02'))->toBeTrue()    // the day they started
        ->and($missing('2026-06-09'))->toBeTrue()    // a half day of leave still leaves half a day to log
        ->and($missing('2026-06-01'))->toBeFalse()   // before they started
        ->and($missing('2026-06-06'))->toBeFalse()   // weekend
        ->and($missing('2026-06-08'))->toBeFalse()   // logged
        ->and($missing('2026-06-10'))->toBeFalse()   // full day of leave
        ->and($missing('2026-06-11'))->toBeFalse()   // bank holiday
        ->and($missing('2026-06-15'))->toBeFalse()   // today is not over yet
        ->and($missing('2026-06-16'))->toBeFalse();  // the future
});

it('moves between months, and an invalid month falls back to the current one', function (): void {
    $world = calendarWorld();
    $page = calendarPage($world['employee'])->assertSee('June 2026');

    $page->call('previousCalendarMonth')->assertSet('calendarMonth', '2026-05')->assertSee('May 2026')
        ->call('nextCalendarMonth')->call('nextCalendarMonth')->assertSet('calendarMonth', '2026-07')->assertSee('July 2026')
        ->call('currentCalendarMonth')->assertSet('calendarMonth', null)->assertSee('June 2026');

    foreach (['2026-13', 'abc', '1999-12', '2100-01', '2026-6', '2026-06-01', "2026-06\n"] as $invalid) {
        $page->set('calendarMonth', $invalid)->assertSee('June 2026');
    }

    // A bad value is replaced as soon as someone navigates, and the range ends are not crossed.
    $page->set('calendarMonth', 'abc')->call('nextCalendarMonth')->assertSet('calendarMonth', '2026-07')
        ->set('calendarMonth', '2099-12')->call('nextCalendarMonth')->assertSet('calendarMonth', '2099-12')
        ->set('calendarMonth', '2000-01')->call('previousCalendarMonth')->assertSet('calendarMonth', '2000-01');
});

it("starts on the month and day it is in the user's own country, not in UTC", function (): void {
    $world = calendarWorld();
    $this->travelTo(Date::parse('2026-06-30 23:30:00')); // already 1 July in Tirane

    $page = calendarPage($world['employee'])->assertSee('July 2026');

    expect(calendarDay($page, '2026-07-01')['isToday'])->toBeTrue()
        ->and(calendarDay($page, '2026-07-01')['isFuture'])->toBeFalse();
});

it('works for a person without a country: the default working week and no holidays', function (): void {
    $world = calendarWorld();
    $world['employee']->update(['country_id' => null]);
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);

    $page = calendarPage($world['employee'])->assertDontSee('Founders Day');

    expect(calendarDay($page, '2026-06-12')['isWorkingDay'])->toBeTrue()->and(calendarDay($page, '2026-06-13')['isWorkingDay'])->toBeFalse();
});

it('opens the log modal with the clicked day and logs on that day, on a task or directly', function (): void {
    $world = calendarWorld();
    $page = calendarPage($world['employee']);

    // The date field holds the day as a timestamp (a date picker's state); the day is what matters.
    $page->mountAction('logTime', ['date' => '2026-06-10'])
        ->assertSet('mountedActions.0.data.date', '2026-06-10 00:00:00')
        ->assertSet('mountedActions.0.data.hours', 0);

    expect(collect($page->instance()->getMountedAction()->getExtraModalFooterActions())->map->getLabel()->all())->toContain('Log & add another');

    $page->unmountAction()
        ->callAction('logTime', ['task_id' => $world['task']->id, 'hours' => 2, 'minutes' => 30, 'description' => 'Worked on it'], ['date' => '2026-06-10'])
        ->assertHasNoActionErrors()->assertNotified('Time logged: 2:30');

    $page->callAction('logTime', ['direct' => true, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id, 'hours' => 0, 'minutes' => 45], ['date' => '2026-06-10'])
        ->assertHasNoActionErrors()->assertNotified('Time logged: 0:45');

    $logs = Log::query()->where('user_id', $world['employee']->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('date')->map->toDateString()->all())->toBe(['2026-06-10', '2026-06-10'])
        ->and($logs->pluck('duration_minutes')->all())->toBe([150, 45])
        ->and($logs->pluck('task_id')->all())->toBe([$world['task']->id, null]);

    // The calendar has the new time on that day without a reload.
    expect(calendarDay($page, '2026-06-10')['total'])->toBe('3:15');
    $page->assertSee('3:15');
});

it('keeps the modal open for "Log & add another", on the same day and with an empty form', function (): void {
    $world = calendarWorld();
    $page = calendarPage($world['employee']);

    // The "Log & add another" button calls the modal's action with `another` set, for that one call.
    $page->mountAction('logTime', ['date' => '2026-06-10'])
        ->fillForm(['task_id' => $world['task']->id, 'hours' => 1, 'minutes' => 0])
        ->call('callMountedAction', ['another' => true])
        ->assertHasNoActionErrors()->assertNotified('Time logged: 1:00')
        ->assertSet('mountedActions.0.data.date', '2026-06-10 00:00:00')
        ->assertSet('mountedActions.0.data.task_id', null)
        ->assertSet('mountedActions.0.data.hours', 0);

    expect($page->instance()->mountedActions)->toHaveCount(1)->and(Log::query()->count())->toBe(1);

    // Then the plain button logs the second one and closes the modal.
    $page->fillForm(['task_id' => $world['task']->id, 'hours' => 2, 'minutes' => 0])->call('callMountedAction');

    expect($page->instance()->mountedActions)->toBe([])
        ->and(Log::query()->where('user_id', $world['employee']->id)->whereDate('date', '2026-06-10')->sum('duration_minutes'))->toEqual(180);
});

it('does not open the modal for a future date, an impossible date or a locked month', function (string $date): void {
    $world = calendarWorld();
    resolve(LockMonth::class)->handle(userWithRole(RoleName::Superadmin), Date::parse('2026-05-10'));

    $page = calendarPage($world['employee'])->mountAction('logTime', ['date' => $date]);

    expect($page->instance()->mountedActions)->toBe([])->and(Log::query()->count())->toBe(0);
})->with(['tomorrow' => '2026-06-16', 'next month' => '2026-07-01', 'not a date' => '2026-02-30', 'text' => 'tomorrow', 'locked month' => '2026-05-12']);

it('opens the modal for today, a past day and, with the right to edit locked months, a locked day', function (): void {
    $world = calendarWorld();
    resolve(LockMonth::class)->handle(userWithRole(RoleName::Superadmin), Date::parse('2026-05-10'));

    foreach (['2026-06-15', '2026-06-01'] as $date) {
        expect(calendarPage($world['employee'])->mountAction('logTime', ['date' => $date])->instance()->mountedActions)->toHaveCount(1);
    }

    expect(calendarPage(userWithRole(RoleName::Admin))->mountAction('logTime', ['date' => '2026-05-12'])->instance()->mountedActions)->toHaveCount(1);
});

it('shows what is wrong inside the modal, keeps it open and saves nothing', function (): void {
    $world = calendarWorld();
    resolve(LockMonth::class)->handle(userWithRole(RoleName::Superadmin), Date::parse('2026-05-10'));
    $page = calendarPage($world['employee']);

    // No time.
    $page->callAction('logTime', ['task_id' => $world['task']->id, 'hours' => 0, 'minutes' => 0], ['date' => '2026-06-10'])
        ->assertHasErrors(['mountedActions.0.data.minutes']);
    expect($page->instance()->mountedActions)->toHaveCount(1);

    // The date was changed in the modal to a locked month.
    $page->fillForm(['date' => '2026-05-12', 'hours' => 1])->call('callMountedAction')
        ->assertHasErrors(['mountedActions.0.data.date']);
    expect($page->instance()->mountedActions)->toHaveCount(1);

    // ...or to a future day.
    $page->fillForm(['date' => '2026-06-20'])->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.date']);

    expect(Log::query()->count())->toBe(0);
});

it('warns in the modal about a bank holiday, a day off or leave, and the warning follows the chosen date', function (): void {
    $world = calendarWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);
    calendarLeave($world, 'annual', '2026-06-09');

    $page = calendarPage($world['employee'])->mountAction('logTime', ['date' => '2026-06-12']);
    expect(modalNotice($page))->toBe('12 June 2026 is a bank holiday (Founders Day).');

    $page->fillForm(['date' => '2026-06-11']);
    expect(modalNotice($page))->toBeNull();
    $page->assertSchemaComponentHidden('dateNotice');

    $page->fillForm(['date' => '2026-06-12 00:00:00']);

    expect(modalNotice($page))->toBe('12 June 2026 is a bank holiday (Founders Day).');

    $page->fillForm(['date' => '2026-06-13']);
    expect(modalNotice($page))->toBe('13 June 2026 is not one of your working days.');

    $page->fillForm(['date' => '2026-06-09']);
    expect(modalNotice($page))->toBe('You are on leave on 9 June 2026 (Annual Leave).');

    expect(LogCalendar::notice($world['employee'], '2026-06-11'))->toBeNull()
        ->and(LogCalendar::notice($world['employee'], 'nonsense'))->toBeNull()
        ->and(LogCalendar::notice($world['employee'], null))->toBeNull()
        ->and($page->instance()->mountedActions)->toHaveCount(1);
});

it('reads the calendar but cannot log without logs.create_own', function (): void {
    $world = calendarWorld();
    Role::findByName(RoleName::Employee->value)->revokePermissionTo(Permissions::LOGS_CREATE_OWN);
    $world['employee']->forgetCachedPermissions();
    calendarLog($world, '2026-06-10');

    $page = calendarPage($world['employee']);

    expect(calendarDay($page, '2026-06-10')['canLog'])->toBeFalse()->and(calendarDay($page, '2026-06-10')['entries'])->toHaveCount(1)
        ->and($page->mountAction('logTime', ['date' => '2026-06-10'])->instance()->mountedActions)->toBe([]);
});

it('has no calendar for someone who cannot see their own logs, even with ?tab=calendar', function (): void {
    $world = calendarWorld();
    $role = Role::findByName(RoleName::Employee->value);
    $role->revokePermissionTo(Permissions::LOGS_VIEW_OWN);
    $role->givePermissionTo(Permissions::LOGS_VIEW_ANY);
    $world['employee']->forgetCachedPermissions();
    $mine = calendarLog($world, '2026-06-10');

    $page = calendarPage($world['employee']);

    expect($page->instance()->getCachedTabs())->toBe([])->and($page->instance()->isCalendarActive())->toBeFalse();
    $page->assertCanSeeTableRecords([$mine])->assertDontSee('Month total');
});

it('only lets people edit entries they may edit, and says when the month is locked', function (): void {
    $world = calendarWorld();
    $log = calendarLog($world, '2026-06-10');
    $editUrl = LogResource::getUrl('edit', ['record' => $log]);

    $page = calendarPage($world['employee'])->assertSee($editUrl, false)->assertDontSee('This month is locked');
    expect(calendarDay($page, '2026-06-10')['entries'][0]['canEdit'])->toBeTrue();

    resolve(LockMonth::class)->handle(userWithRole(RoleName::Superadmin), Date::parse('2026-06-01'));

    $page = calendarPage($world['employee'])->assertDontSee($editUrl, false)->assertSee('This month is locked, so time can');
    expect(calendarDay($page, '2026-06-10')['entries'][0]['canEdit'])->toBeFalse()
        ->and(calendarDay($page, '2026-06-10')['canLog'])->toBeFalse()
        ->and(calendarDay($page, '2026-06-12')['canLog'])->toBeFalse();

    // Someone who may edit locked months can still log and edit, and is told the month is locked.
    $admin = userWithRole(RoleName::Admin);
    $ownLog = Log::factory()->create(['user_id' => $admin->id, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id, 'date' => '2026-06-10']);

    $page = calendarPage($admin)->assertSee(LogResource::getUrl('edit', ['record' => $ownLog]), false)->assertSee('You can still log and edit');
    expect(calendarDay($page, '2026-06-12')['canLog'])->toBeTrue();
});

it('never sends a rate to the browser, and keeps its helper methods out of reach', function (): void {
    $world = calendarWorld();
    $superadmin = userWithRole(RoleName::Superadmin);
    Log::factory()->create(['user_id' => $superadmin->id, 'project_id' => $world['project']->id, 'technology_id' => $world['technology']->id, 'date' => '2026-06-10', 'rate_minor' => 3333]);

    $page = calendarPage($superadmin)->assertDontSee('33.33')->assertDontSee('3333');

    expect(json_encode($page->instance()->calendar()))->not->toContain('rate');

    // A real update request from the browser may not call the render helpers.
    $html = $this->get('/app/logs?tab=calendar')->assertOk()->getContent();

    foreach (['calendar', 'calendarStart', 'isCalendarActive'] as $method) {
        livewireUpdate((string) $html, 'ListLogs', $method)->assertForbidden();
    }

    livewireUpdate((string) $html, 'ListLogs', 'nextCalendarMonth')->assertOk();
});
