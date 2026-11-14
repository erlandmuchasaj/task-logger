<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permissions;
use App\Domain\Leave\Actions\DecideLeave;
use App\Domain\Leave\Enums\ApprovalDecision;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\LeaveType;
use App\Filament\Resources\LeaveRequests\Pages\ManageLeaveRequests;
use App\Filament\Resources\LeaveRequests\Support\LeaveCalendar;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * June 2026 starts on a Monday: the 6th and 7th are a weekend, the 30th is a Tuesday. "Now" is Monday the 15th.
 */
beforeEach(fn () => $this->travelTo(Date::parse('2026-06-15 10:00:00')));

/**
 * An employee in Albania (Monday to Friday week) with the leave types and 20 days of annual leave.
 *
 * @return array<string, mixed>
 */
function lcWorld(): array
{
    $world = leaveSetup();
    $world['employee']->update(['name' => 'Zoe', 'surname' => 'Zed']);
    grantDays($world['employee'], $world['annual'], '20.0');

    return $world;
}

/**
 * Somebody else, in the world's country unless told otherwise.
 *
 * @param  array<string, mixed>  $world
 * @param  array<string, mixed>  $attributes
 */
function lcPerson(array $world, string $name, string $surname = 'Person', array $attributes = [], RoleName $role = RoleName::Employee): User
{
    return User::factory()->withRole($role)->create(array_merge(['country_id' => $world['country']->id, 'name' => $name, 'surname' => $surname], $attributes));
}

/** @param  array<string, mixed>  $overrides */
function lcLeave(User $user, LeaveType $type, string $from, ?string $to = null, LeaveStatus $status = LeaveStatus::Approved, DayPart $start = DayPart::Full, DayPart $end = DayPart::Full, array $overrides = []): LeaveRequest
{
    return LeaveRequest::factory()->create(array_merge([
        'user_id' => $user->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to ?? $from,
        'start_day_part' => $start, 'end_day_part' => $end, 'status' => $status,
    ], $overrides));
}

function lcPage(User $user, ?string $month = '2026-06', bool $onlyMine = false): Testable
{
    test()->actingAs($user);

    return Livewire::test(ManageLeaveRequests::class)->set('activeTab', 'calendar')->set('calendarMonth', $month)->set('onlyMine', $onlyMine);
}

/** @return array<string, mixed> */
function lcDay(Testable $page, string $date): array
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

/** @return list<string> The chip labels of one day. */
function lcLabels(Testable $page, string $date): array
{
    return array_column(lcDay($page, $date)['leave'], 'label');
}

/** Every chip label on the month, joined, to ask "is this person on the calendar at all". */
function lcEverything(Testable $page): string
{
    $labels = [];

    foreach ($page->instance()->calendar()['weeks'] as $week) {
        foreach ($week as $day) {
            $labels = [...$labels, ...($day === null ? [] : array_column($day['leave'], 'label'))];
        }
    }

    return implode('|', $labels);
}

/** What the open request modal says about the dates and what they cost, or null while that part is hidden. */
function lcModal(Testable $page, string $key): ?string
{
    $schema = $page->instance()->{$page->instance()->getMountedActionSchemaName()};
    $callout = $schema->getFlatComponents(withHidden: false)[$key] ?? null;

    return $callout === null ? null : (string) ($key === 'dateNotice' ? $callout->getDescription() : $callout->getHeading());
}

/** The text of the open details modal, tags and extra white space removed. */
function lcDetails(Testable $page): string
{
    return mb_trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $page->instance()->getMountedAction()->getModalContent()))));
}

it('opens on the table, and the tabs switch between the table and the calendar', function (): void {
    $world = lcWorld();
    $mine = lcLeave($world['employee'], $world['annual'], '2026-06-10', status: LeaveStatus::Pending, overrides: ['reason' => 'Family trip']);
    $this->actingAs($world['employee']);

    Livewire::test(ManageLeaveRequests::class)
        ->assertSet('activeTab', 'table')
        ->assertCanSeeTableRecords([$mine])
        ->assertSee('Family trip')
        ->assertDontSee('Only mine')
        ->set('activeTab', 'calendar')
        ->assertSee('Only mine')->assertSee('June 2026')
        ->assertDontSee('Family trip')
        ->set('activeTab', 'table')
        ->assertSee('Family trip')
        ->assertDontSee('Only mine');

    // The tab, the month and the filter live in the URL, so a calendar link can be shared or reloaded.
    Livewire::withQueryParams(['tab' => 'calendar', 'month' => '2026-05', 'mine' => '1'])->test(ManageLeaveRequests::class)
        ->assertSee('May 2026')->assertSet('onlyMine', true)->assertDontSee('Family trip');
    $this->get('/app/leave-requests?tab=calendar')->assertOk()->assertSee('Only mine');
    $this->get('/app/leave-requests')->assertOk()->assertDontSee('Only mine');
});

it('renders the action modals on both tabs, because the request and details modals open from the calendar', function (): void {
    $world = lcWorld();
    $this->actingAs($world['employee']);

    // Filament renders a page's modals inside its table when the page has one; the calendar tab hides the table.
    Livewire::test(ManageLeaveRequests::class)->assertSeeHtml('wire:partial="action-modals"')
        ->set('activeTab', 'calendar')->assertSeeHtml('wire:partial="action-modals"')
        ->set('activeTab', 'table')->assertSeeHtml('wire:partial="action-modals"');
});

it('has no calendar without bank_holidays.view, even with ?tab=calendar, and no old calendar address either', function (): void {
    $world = lcWorld();
    Role::findByName(RoleName::Employee->value)->revokePermissionTo(Permissions::BANK_HOLIDAYS_VIEW);
    $world['employee']->forgetCachedPermissions();
    $mine = lcLeave($world['employee'], $world['annual'], '2026-06-10', status: LeaveStatus::Pending);
    $this->actingAs($world['employee']);

    $page = Livewire::withQueryParams(['tab' => 'calendar'])->test(ManageLeaveRequests::class);

    expect($page->instance()->getCachedTabs())->toBe([])->and($page->instance()->isCalendarActive())->toBeFalse();
    $page->assertCanSeeTableRecords([$mine])->assertDontSee('Only mine');
    $this->get('/app/leave-calendar')->assertForbidden();
});

it("D-44 D-56 shows everybody's approved and taken leave, and pending only where the policy lets the viewer see it", function (): void {
    $world = lcWorld();
    $lead = lcPerson($world, 'Lena', 'Lead');
    $lead->givePermissionTo(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS); // a manager without view_any
    $lead->forgetCachedPermissions();

    $report = lcPerson($world, 'Rita', 'Report', ['manager_id' => $lead->id]);
    $berta = lcPerson($world, 'Berta', 'Approved');
    $carl = lcPerson($world, 'Carl', 'Taken');
    $dora = lcPerson($world, 'Dora', 'Pending');

    lcLeave($berta, $world['annual'], '2026-06-08');
    lcLeave($carl, $world['annual'], '2026-06-09', status: LeaveStatus::Taken);
    lcLeave($dora, $world['annual'], '2026-06-10', status: LeaveStatus::Pending);
    lcLeave($report, $world['annual'], '2026-06-11', status: LeaveStatus::Pending);
    lcLeave($world['employee'], $world['annual'], '2026-06-12', status: LeaveStatus::Pending);

    $employee = lcEverything(lcPage($world['employee']));
    expect($employee)->toContain('Berta Approved')->toContain('Carl Taken')->toContain('Zoe Zed')
        ->not->toContain('Dora Pending')->not->toContain('Rita Report');

    $manager = lcEverything(lcPage($lead));
    expect($manager)->toContain('Berta Approved')->toContain('Carl Taken')->toContain('Rita Report')
        ->not->toContain('Dora Pending')->not->toContain('Zoe Zed');

    foreach ([RoleName::Hr, RoleName::Admin, RoleName::Superadmin] as $role) {
        expect(lcEverything(lcPage(lcPerson($world, 'View', 'Any', role: $role))))
            ->toContain('Berta Approved')->toContain('Carl Taken')->toContain('Dora Pending')->toContain('Rita Report')->toContain('Zoe Zed');
    }
});

it('does not show leave that is rejected, cancelled or deleted, or that belongs to a person who was deleted', function (): void {
    $world = lcWorld();
    lcLeave(lcPerson($world, 'Ann', 'Rejected'), $world['annual'], '2026-06-08', status: LeaveStatus::Rejected);
    lcLeave(lcPerson($world, 'Bob', 'Cancelled'), $world['annual'], '2026-06-09', status: LeaveStatus::Cancelled);
    lcLeave(lcPerson($world, 'Cy', 'Deleted'), $world['annual'], '2026-06-10')->delete();
    $ghost = lcPerson($world, 'Dee', 'Ghost');
    lcLeave($ghost, $world['annual'], '2026-06-11');
    lcLeave(lcPerson($world, 'Eve', 'Visible'), $world['annual'], '2026-06-12');
    $ghost->delete();

    $page = lcPage($world['employee']);

    expect(lcEverything($page))->toBe('Eve Visible');
    $page->assertOk();
});

it("names the leave type only on requests the viewer may open, and sends a colleague's request no id", function (): void {
    $world = lcWorld();
    $berta = lcPerson($world, 'Berta', 'Sick');
    $theirs = lcLeave($berta, $world['sick'], '2026-06-08', overrides: ['reason' => 'Private surgery']);
    $mine = lcLeave($world['employee'], $world['annual'], '2026-06-09', status: LeaveStatus::Pending);

    $page = lcPage($world['employee']);

    expect(lcDay($page, '2026-06-08')['leave'])->toBe([['id' => null, 'label' => 'Berta Sick', 'title' => 'Berta Sick · Approved', 'pending' => false, 'mine' => false]])
        ->and(lcDay($page, '2026-06-09')['leave'])->toBe([['id' => $mine->id, 'label' => 'Zoe Zed · Annual Leave', 'title' => 'Zoe Zed · Annual Leave · Pending', 'pending' => true, 'mine' => true]])
        ->and(json_encode($page->instance()->calendar()))->not->toContain('Private surgery')->not->toContain('Sick Leave');

    $page->assertDontSee('Private surgery')->assertDontSee('Sick Leave')
        ->assertSeeHtml("mountAction('leaveDetails', { id: {$mine->id} })")
        ->assertDontSeeHtml("mountAction('leaveDetails', { id: {$theirs->id} })");

    // HR may open it, so HR sees the type.
    $hr = lcPage(lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr));
    expect(lcDay($hr, '2026-06-08')['leave'][0])->toMatchArray(['id' => $theirs->id, 'label' => 'Berta Sick · Sick Leave']);
});

it("puts the viewer's own leave first on a day, then the others by name", function (): void {
    $world = lcWorld();
    lcLeave(lcPerson($world, 'Max', 'Mid'), $world['annual'], '2026-06-08');
    lcLeave($world['employee'], $world['annual'], '2026-06-08');
    lcLeave(lcPerson($world, 'abe', 'Alpha'), $world['annual'], '2026-06-08');

    $chips = lcDay(lcPage($world['employee']), '2026-06-08')['leave'];

    expect(array_column($chips, 'label'))->toBe(['Zoe Zed · Annual Leave', 'abe Alpha', 'Max Mid'])
        ->and(array_column($chips, 'mine'))->toBe([true, false, false]);
});

it("keeps only the viewer's own leave and own country's holidays with Only mine, where HR sees every country's", function (): void {
    $world = lcWorld();
    $other = Country::factory()->create(['code' => 'XK']);
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Labour Day', 'start_date' => '2026-06-03', 'end_date' => '2026-06-03']);
    BankHoliday::factory()->create(['country_id' => $other->id, 'name' => 'Foreign Day', 'start_date' => '2026-06-04', 'end_date' => '2026-06-04']);
    $hr = lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr);
    lcLeave($hr, $world['annual'], '2026-06-08');
    lcLeave(lcPerson($world, 'Berta', 'Other'), $world['annual'], '2026-06-09');

    $everyone = lcPage($hr);
    expect(lcDay($everyone, '2026-06-03')['holidays'])->toBe(['Labour Day (AL)'])
        ->and(lcDay($everyone, '2026-06-04')['holidays'])->toBe(['Foreign Day (XK)'])
        ->and(lcEverything($everyone))->toContain('Hana Hr')->toContain('Berta Other');

    $mineOnly = lcPage($hr, onlyMine: true);
    expect(lcDay($mineOnly, '2026-06-03')['holidays'])->toBe(['Labour Day'])
        ->and(lcDay($mineOnly, '2026-06-04')['holidays'])->toBe([])
        ->and(lcEverything($mineOnly))->toBe('Hana Hr · Annual Leave');
});

it("shows the viewer's country's bank holidays and not other countries', also from one that began the month before", function (): void {
    $world = lcWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Spring Break', 'start_date' => '2026-05-30', 'end_date' => '2026-06-02']);
    BankHoliday::factory()->create(['name' => 'Foreign Day', 'start_date' => '2026-06-10', 'end_date' => '2026-06-10']);

    $page = lcPage($world['employee']);

    expect(lcDay($page, '2026-06-01')['holidays'])->toBe(['Spring Break'])
        ->and(lcDay($page, '2026-06-02')['holidays'])->toBe(['Spring Break'])
        ->and(lcDay($page, '2026-06-03')['holidays'])->toBe([])
        ->and(lcDay($page, '2026-06-10')['holidays'])->toBe([]);
    $page->assertDontSee('Foreign Day');
});

it('paints a request only on the days it is charged: not on a weekend or a bank holiday, with the half days marked', function (): void {
    $world = lcWorld();
    lcLeave($world['employee'], $world['annual'], '2026-06-05', '2026-06-09', start: DayPart::Pm, end: DayPart::Am);

    $page = lcPage($world['employee']);

    expect(lcLabels($page, '2026-06-05'))->toBe(['Zoe Zed · Annual Leave (PM)'])
        ->and(lcLabels($page, '2026-06-06'))->toBe([])
        ->and(lcLabels($page, '2026-06-07'))->toBe([])
        ->and(lcLabels($page, '2026-06-08'))->toBe(['Zoe Zed · Annual Leave'])
        ->and(lcLabels($page, '2026-06-09'))->toBe(['Zoe Zed · Annual Leave (AM)']);

    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-08', 'end_date' => '2026-06-08']);

    $page = lcPage($world['employee']);
    expect(lcLabels($page, '2026-06-08'))->toBe([])->and(lcDay($page, '2026-06-08')['holidays'])->toBe(['Founders Day']);
});

it("paints each person by their own country's working week and holidays, not the viewer's", function (): void {
    $world = lcWorld();
    $sixDays = Country::factory()->create(['code' => 'XK', 'working_days' => [1, 2, 3, 4, 5, 6]]);
    BankHoliday::factory()->create(['country_id' => $sixDays->id, 'name' => 'Their Day', 'start_date' => '2026-06-08', 'end_date' => '2026-06-08']);
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Our Day', 'start_date' => '2026-06-05', 'end_date' => '2026-06-05']);
    $sam = lcPerson($world, 'Sam', 'Six', ['country_id' => $sixDays->id]);
    lcLeave($sam, $world['annual'], '2026-06-05', '2026-06-08');

    $page = lcPage($world['employee']);

    expect(lcLabels($page, '2026-06-05'))->toBe(['Sam Six'])   // our holiday is not theirs
        ->and(lcLabels($page, '2026-06-06'))->toBe(['Sam Six']) // they work Saturdays
        ->and(lcLabels($page, '2026-06-07'))->toBe([])          // and not Sundays
        ->and(lcLabels($page, '2026-06-08'))->toBe([]);          // their holiday is not ours
});

it('works for a person without a country: the default working week, no holidays', function (): void {
    $world = lcWorld();
    $nomad = lcPerson($world, 'Nomad', 'Nowhere', ['country_id' => null]);
    lcLeave($nomad, $world['annual'], '2026-06-05', '2026-06-08');
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Our Day', 'start_date' => '2026-06-08', 'end_date' => '2026-06-08']);

    $others = lcPage($world['employee']);
    expect(lcLabels($others, '2026-06-05'))->toBe(['Nomad Nowhere'])
        ->and(lcLabels($others, '2026-06-06'))->toBe([])
        ->and(lcLabels($others, '2026-06-08'))->toBe(['Nomad Nowhere']); // no country, so our holiday does not apply to them

    $page = lcPage($nomad);
    expect(lcDay($page, '2026-06-08')['holidays'])->toBe([])->and(lcDay($page, '2026-06-06')['isWorkingDay'])->toBeFalse();
    $page->assertOk();
});

it('shows a request from the days of this month only, when it began before it or ends after it', function (): void {
    $world = lcWorld();
    lcLeave($world['employee'], $world['annual'], '2026-05-29', '2026-06-02');
    lcLeave($world['employee'], $world['annual'], '2026-06-29', '2026-07-03', overrides: ['reason' => 'Long']);

    $june = lcPage($world['employee']);
    $july = lcPage($world['employee'], '2026-07');

    expect(lcLabels($june, '2026-06-01'))->toHaveCount(1)->and(lcLabels($june, '2026-06-02'))->toHaveCount(1)
        ->and(lcLabels($june, '2026-06-29'))->toHaveCount(1)->and(lcLabels($june, '2026-06-30'))->toHaveCount(1)
        ->and(lcLabels($july, '2026-07-01'))->toHaveCount(1)->and(lcLabels($july, '2026-07-03'))->toHaveCount(1)
        ->and(lcLabels($july, '2026-07-02'))->toHaveCount(1)
        ->and(fn (): array => lcDay($june, '2026-05-29'))->toThrow(RuntimeException::class);
});

it('moves between months, an invalid month falls back to the current one, and the range stops at the end', function (): void {
    $world = lcWorld();
    $this->actingAs($world['employee']);

    Livewire::test(ManageLeaveRequests::class)->set('activeTab', 'calendar')
        ->assertSet('calendarMonth', null)->assertSee('June 2026')
        ->call('nextCalendarMonth')->assertSet('calendarMonth', '2026-07')->assertSee('July 2026')
        ->call('previousCalendarMonth')->call('previousCalendarMonth')->assertSet('calendarMonth', '2026-05')->assertSee('May 2026')
        ->call('currentCalendarMonth')->assertSet('calendarMonth', null)->assertSee('June 2026')
        ->set('calendarMonth', 'garbage')->assertSee('June 2026')
        ->set('calendarMonth', '2026-13')->assertSee('June 2026')
        ->set('calendarMonth', '2099-12')->call('nextCalendarMonth')->assertSet('calendarMonth', '2099-12');
});

it("starts on the day it is in the user's own country, not in UTC", function (): void {
    $world = lcWorld();
    $auckland = lcPerson($world, 'Kia', 'Ora', ['country_id' => Country::factory()->create(['timezone' => 'Pacific/Auckland'])->id]);
    $this->travelTo(Date::parse('2026-06-15 23:00:00', 'UTC')); // Tuesday 11:00 in Auckland

    $page = lcPage($auckland);

    expect(lcDay($page, '2026-06-16')['isToday'])->toBeTrue()->and(lcDay($page, '2026-06-15')['isToday'])->toBeFalse();
});

it('opens the request form with the dates of a click or a drag, either way round, and takes nothing else from the browser', function (): void {
    $world = lcWorld();
    $page = lcPage($world['employee']);

    $page->mountAction('request', ['start' => '2026-06-10', 'end' => '2026-06-12'])
        ->assertSet('mountedActions.0.data.start_date', '2026-06-10 00:00:00')
        ->assertSet('mountedActions.0.data.end_date', '2026-06-12 00:00:00')
        ->assertSet('mountedActions.0.data.start_day_part', 'full')
        ->assertSet('mountedActions.0.data.end_day_part', 'full')
        ->unmountAction();

    $page->mountAction('request', ['start' => '2026-06-10', 'end' => '2026-06-10'])
        ->assertSet('mountedActions.0.data.start_date', '2026-06-10 00:00:00')
        ->assertSet('mountedActions.0.data.end_date', '2026-06-10 00:00:00')
        ->unmountAction();

    // A drag from the right to the left.
    $page->mountAction('request', ['start' => '2026-06-12', 'end' => '2026-06-10'])
        ->assertSet('mountedActions.0.data.start_date', '2026-06-10 00:00:00')
        ->assertSet('mountedActions.0.data.end_date', '2026-06-12 00:00:00')
        ->unmountAction();

    // Not real dates, or nothing: the empty form, as the header button has always opened it.
    foreach ([['start' => '2026-02-30', 'end' => '2026-06-10'], ['start' => '2026-06-10'], ['start' => ['2026-06-10'], 'end' => '2026-06-10'], []] as $arguments) {
        $page->mountAction('request', $arguments)
            ->assertSet('mountedActions.0.data.start_date', null)
            ->assertSet('mountedActions.0.data.end_date', null)
            ->assertSet('mountedActions.0.data.start_day_part', 'full')
            ->unmountAction();
    }

    // Only the two dates are read; the type and the reason stay for the person to choose.
    $page->mountAction('request', ['start' => '2026-06-10', 'end' => '2026-06-10', 'leave_type_id' => $world['sick']->id, 'reason' => 'Prefilled', 'start_day_part' => 'pm'])
        ->assertSet('mountedActions.0.data.leave_type_id', null)
        ->assertSet('mountedActions.0.data.reason', null)
        ->assertSet('mountedActions.0.data.start_day_part', 'full');
});

it('requests leave from the calendar by the same rules as the header button, and shows it at once as pending', function (): void {
    $world = lcWorld();
    $page = lcPage($world['employee']);

    $page->callAction('request', [
        'leave_type_id' => $world['annual']->id, 'start_date' => '2026-06-10', 'end_date' => '2026-06-12',
        'start_day_part' => 'full', 'end_day_part' => 'full', 'reason' => 'Family trip',
    ], ['start' => '2026-06-10', 'end' => '2026-06-12'])
        ->assertHasNoActionErrors()->assertNotified('Leave requested: 3.0 days');

    $request = LeaveRequest::query()->where('user_id', $world['employee']->id)->sole();

    expect($request)->status->toBe(LeaveStatus::Pending)->days_requested->toBe('3.0')->created_by->toBe($world['employee']->id)
        ->and([$request->start_date->toDateString(), $request->end_date->toDateString()])->toBe(['2026-06-10', '2026-06-12'])
        ->and($page->instance()->mountedActions)->toBe([])
        ->and(lcDay($page, '2026-06-11')['leave'][0])->toMatchArray(['pending' => true, 'mine' => true, 'id' => $request->id]);
});

it('keeps the request form open and saves nothing when the server refuses, and cannot be tricked into overlapping leave', function (): void {
    $world = lcWorld();
    lcLeave($world['employee'], $world['annual'], '2026-06-10', status: LeaveStatus::Approved);
    $fields = ['leave_type_id' => $world['annual']->id, 'start_day_part' => 'full', 'end_day_part' => 'full'];

    $page = lcPage($world['employee'])
        ->callAction('request', [...$fields, 'start_date' => '2026-06-06', 'end_date' => '2026-06-07'], ['start' => '2026-06-06', 'end' => '2026-06-07'])
        ->assertNotified('The selected dates contain no working days.');

    expect($page->instance()->mountedActions)->toHaveCount(1);

    $page->unmountAction()
        ->callAction('request', [...$fields, 'start_date' => '2026-06-09', 'end_date' => '2026-06-11'], ['start' => '2026-06-09', 'end' => '2026-06-11'])
        ->assertNotified('You already have leave in that period (2026-06-10 to 2026-06-10).');

    expect(LeaveRequest::query()->where('status', LeaveStatus::Pending)->count())->toBe(0);
});

it('warns in the request form about what the server would refuse or what is worth a second look, and follows the chosen dates', function (): void {
    $world = lcWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);

    $page = lcPage($world['employee'])->mountAction('request', ['start' => '2026-06-12', 'end' => '2026-06-12']);
    expect(lcModal($page, 'dateNotice'))->toBe('The selected dates contain no working days, so this request would be refused. Bank holidays are not charged: Founders Day.');

    $page->fillForm(['start_date' => '2026-06-10', 'end_date' => '2026-06-11']);
    expect(lcModal($page, 'dateNotice'))->toBeNull();
    $page->assertSchemaComponentHidden('dateNotice');

    $page->fillForm(['start_date' => '2026-06-10 00:00:00', 'end_date' => '2026-06-12 00:00:00']);

    expect(lcModal($page, 'dateNotice'))->toBe('Bank holidays are not charged: Founders Day.');

    $page->fillForm(['start_date' => '2026-06-13', 'end_date' => '2026-06-15']);
    expect(lcModal($page, 'dateNotice'))->toBe('13 June 2026 is not one of your working days.');

    // The date field was emptied: nothing to say yet, and nothing breaks.
    $page->fillForm(['start_date' => null, 'end_date' => null]);
    expect(lcModal($page, 'dateNotice'))->toBeNull()->and($page->instance()->mountedActions)->toHaveCount(1);
});

it('tells the person what the dates cost and what is left of their balance, as they choose them', function (): void {
    $world = lcWorld();
    BankHoliday::factory()->create(['country_id' => $world['country']->id, 'name' => 'Founders Day', 'start_date' => '2026-06-12', 'end_date' => '2026-06-12']);

    $page = lcPage($world['employee'])->mountAction('request');
    $page->assertSchemaComponentHidden('daySummary');

    $page->fillForm(['start_date' => '2026-06-10', 'end_date' => '2026-06-12']);

    expect(lcModal($page, 'daySummary'))->toBe('Uses 2.0 days');

    $page->fillForm(['leave_type_id' => $world['annual']->id]);
    expect(lcModal($page, 'daySummary'))->toBe('Uses 2.0 days · 20.0 available (Annual Leave)');

    $page->fillForm(['start_day_part' => 'pm']);
    expect(lcModal($page, 'daySummary'))->toBe('Uses 1.5 days · 20.0 available (Annual Leave)');

    $page->fillForm(['leave_type_id' => $world['unpaid']->id]);
    expect(lcModal($page, 'daySummary'))->toBe('Uses 1.5 days');
});

it('BR-23 the warning counts a Saturday inside an existing Friday to Monday request, like the server does', function (): void {
    $world = lcWorld();
    $user = $world['employee'];
    lcLeave($user, $world['annual'], '2026-06-12', '2026-06-15');   // Fri to Mon: the calendar paints Fri and Mon only
    lcLeave($user, $world['annual'], '2026-06-16', status: LeaveStatus::Approved, start: DayPart::Am);
    lcLeave($user, $world['annual'], '2026-06-17', status: LeaveStatus::Rejected);

    expect(LeaveCalendar::notice($user, '2026-06-13', '2026-06-14'))
        ->toBe('The selected dates contain no working days, so this request would be refused. You already have leave in that period (12 Jun - 15 Jun, approved).')
        ->and(LeaveCalendar::notice($user, '2026-06-16', '2026-06-16', 'pm', 'pm'))->toBeNull()        // the other half of a half day
        ->and(LeaveCalendar::notice($user, '2026-06-16', '2026-06-16', 'full', 'full'))->toBe('You already have leave in that period (16 Jun, approved).')
        ->and(LeaveCalendar::notice($user, '2026-06-17', '2026-06-17'))->toBeNull()                    // a rejected request is not in the way
        ->and(LeaveCalendar::notice(lcPerson($world, 'Other'), '2026-06-12', '2026-06-12'))->toBeNull(); // nor is somebody else's
});

it('gives no warning and no summary for dates that are not (yet) a valid request', function (mixed $start, mixed $end): void {
    $world = lcWorld();

    expect(LeaveCalendar::notice($world['employee'], $start, $end))->toBeNull()
        ->and(LeaveCalendar::summary($world['employee'], $world['annual']->id, $start, $end))->toBeNull();
})->with([
    'nothing' => [null, null],
    'not dates' => ['nope', 'nope'],
    'only a start' => ['2026-06-10', null],
    'not a real date' => ['2026-02-30', '2026-03-02'],
    'backwards' => ['2026-06-12', '2026-06-10'],
    'longer than a request may be' => ['2026-01-01', '2028-01-01'],
    'an array' => [['2026-06-10'], ['2026-06-10']],
]);

it('accepts the date as a date object or with a time part, and says when the person has no country', function (): void {
    $world = lcWorld();

    expect(LeaveCalendar::notice($world['employee'], Date::parse('2026-06-13'), Date::parse('2026-06-14')))->toContain('no working days')
        ->and(LeaveCalendar::notice($world['employee'], '2026-06-13 00:00:00', '2026-06-14T00:00:00.000000Z'))->toContain('no working days');

    $nomad = lcPerson($world, 'Nomad', 'Nowhere', ['country_id' => null]);

    expect(LeaveCalendar::notice($nomad, '2026-06-10', '2026-06-11'))->toBe('You have no country, so working days and holidays are unknown and this request would be refused.')
        ->and(LeaveCalendar::summary($nomad, $world['annual']->id, '2026-06-10', '2026-06-11'))->toBeNull();
});

it('BR-24 the summary counts the requests still waiting and says when the balance is not enough', function (): void {
    $world = lcWorld();
    $user = $world['employee'];

    expect(LeaveCalendar::summary($user, $world['annual']->id, '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days · 20.0 available (Annual Leave)');

    lcLeave($user, $world['annual'], '2026-06-22', '2026-06-23', LeaveStatus::Pending, overrides: ['days_requested' => '2.0']);

    expect(LeaveCalendar::summary($user, $world['annual']->id, '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days · 18.0 available (Annual Leave)')
        ->and(LeaveCalendar::summary($user, (string) $world['annual']->id, '2026-06-01', '2026-06-30'))->toBe('Uses 22.0 days · 18.0 available (Annual Leave), not enough balance')
        ->and(LeaveCalendar::summary($user, null, '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days')
        ->and(LeaveCalendar::summary($user, 'garbage', '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days')
        ->and(LeaveCalendar::summary($user, 999999, '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days')
        ->and(LeaveCalendar::summary($user, $world['unpaid']->id, '2026-06-10', '2026-06-12'))->toBe('Uses 3.0 days')
        ->and(LeaveCalendar::summary($user, $world['annual']->id, '2026-06-13', '2026-06-14'))->toBeNull(); // nothing to charge
});

it('reads the calendar but cannot request without leave_requests.create_own', function (): void {
    $world = lcWorld();
    Role::findByName(RoleName::Employee->value)->revokePermissionTo(Permissions::LEAVE_REQUESTS_CREATE_OWN);
    $world['employee']->forgetCachedPermissions();
    lcLeave(lcPerson($world, 'Berta', 'Colleague'), $world['annual'], '2026-06-10');

    $page = lcPage($world['employee']);

    expect($page->instance()->calendar()['canRequest'])->toBeFalse()
        ->and(lcLabels($page, '2026-06-10'))->toBe(['Berta Colleague'])
        ->and($page->mountAction('request', ['start' => '2026-06-10', 'end' => '2026-06-10'])->instance()->mountedActions)->toBe([]);
    $page->assertDontSee('Request leave on')->assertDontSee('drag across days')->assertDontSeeHtml("begin('");

    // With the right (HR has its own copy of it), the same page has the day buttons and the drag handlers.
    $allowed = lcPage(lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr));
    expect($allowed->instance()->calendar()['canRequest'])->toBeTrue();
    $allowed->assertSee('Request leave on 10 June 2026')->assertSee('drag across days')->assertSeeHtml("begin('2026-06-10'");
});

it("opens the details of a request the person may see: their own, a report's for a manager, anybody's for HR", function (): void {
    $world = lcWorld();
    $lead = lcPerson($world, 'Lena', 'Lead');
    $lead->givePermissionTo(Permissions::LEAVE_REQUESTS_APPROVE_DIRECT_REPORTS);
    $lead->forgetCachedPermissions();

    $report = lcPerson($world, 'Rita', 'Report', ['manager_id' => $lead->id]);
    $mine = lcLeave($world['employee'], $world['annual'], '2026-06-10', '2026-06-12', LeaveStatus::Pending, overrides: ['reason' => 'Family trip', 'days_requested' => '3.0']);
    $theirs = lcLeave($report, $world['sick'], '2026-06-11', status: LeaveStatus::Pending, overrides: ['reason' => 'Flu']);

    $own = lcPage($world['employee'])->mountAction('leaveDetails', ['id' => $mine->id]);
    expect($own->instance()->mountedActions)->toHaveCount(1)
        ->and(lcDetails($own))->toContain('Employee Zoe Zed')->toContain('Type Annual Leave')->toContain('Dates 10 Jun 2026 to 12 Jun 2026')->toContain('Days 3.0')
        ->toContain('Status Pending')->toContain('Manager: n/a')->toContain('Hr: waiting')->toContain('Reason Family trip');

    $report = lcPage($lead)->mountAction('leaveDetails', ['id' => $theirs->id]);
    expect($report->instance()->mountedActions)->toHaveCount(1)
        ->and(lcDetails($report))->toContain('Employee Rita Report')->toContain('Type Sick Leave')->toContain('Reason Flu');

    $hr = lcPage(lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr));
    expect($hr->mountAction('leaveDetails', ['id' => $mine->id])->instance()->mountedActions)->toHaveCount(1);
});

it('does not open the details of a request the person may not see, or of anything that is not a request id', function (): void {
    $world = lcWorld();
    $colleague = lcLeave(lcPerson($world, 'Berta', 'Colleague'), $world['annual'], '2026-06-08', overrides: ['reason' => 'Private reason']);
    $pending = lcLeave(lcPerson($world, 'Dora', 'Pending'), $world['annual'], '2026-06-09', status: LeaveStatus::Pending);
    $page = lcPage($world['employee']);

    // Their approved leave is on the calendar by name, but it is not theirs to open (D-44).
    foreach ([$colleague->id, (string) $colleague->id, $pending->id, 999999, 0, -1, 'abc', '1e3', '2.5', '', ' ', 1.5, true, ['x'], null] as $id) {
        expect($page->mountAction('leaveDetails', ['id' => $id])->instance()->mountedActions)->toBe([], 'id '.json_encode($id));
    }

    expect($page->mountAction('leaveDetails')->instance()->mountedActions)->toBe([]);
    $page->assertDontSee('Private reason');
});

it('D-07 BR-20 BR-21 decides and cancels from the details with the same rules as the table, and closes the modal', function (): void {
    $manager = User::factory()->withRole(RoleName::Admin)->create();
    $world = leaveSetup(manager: $manager);
    grantDays($world['employee'], $world['annual'], '10.0');
    $hr = User::factory()->withRole(RoleName::Hr)->create(['country_id' => $world['country']->id]);
    $request = lcLeave($world['employee'], $world['annual'], '2026-06-22', '2026-06-23', LeaveStatus::Pending, overrides: ['days_requested' => '2.0']);
    $open = TestAction::make('leaveDetails')->arguments(['id' => $request->id]);

    // The manager decides first: HR has nothing to press yet, and the manager no HR button.
    lcPage($hr)->assertActionHidden([$open, 'hrApprove'])->assertActionHidden([$open, 'managerApprove']);
    $page = lcPage($manager)->assertActionVisible([$open, 'managerApprove'])->assertActionHidden([$open, 'hrApprove']);

    // BR-21: a rejection needs its note.
    $page->callAction([$open, 'managerReject'], ['note' => ''])->assertHasActionErrors(['note' => 'required']);
    expect($request->refresh()->status)->toBe(LeaveStatus::Pending);
    $page->unmountAction()->unmountAction();

    $page->callAction([$open, 'managerApprove'])->assertNotified('Decision recorded');
    expect($page->instance()->mountedActions)->toBe([])
        ->and($request->refresh()->status)->toBe(LeaveStatus::Pending)
        ->and(lcDay($page, '2026-06-22')['leave'][0]['pending'])->toBeTrue();

    $page = lcPage($hr)->assertActionVisible([$open, 'hrApprove'])->callAction([$open, 'hrApprove'])->assertNotified('Decision recorded');
    expect($page->instance()->mountedActions)->toBe([])
        ->and($request->refresh()->status)->toBe(LeaveStatus::Approved)
        ->and(lcDay($page, '2026-06-22')['leave'][0]['pending'])->toBeFalse(); // a solid chip now

    // The owner takes it back (D-44): cancelled leave leaves the calendar.
    $page = lcPage($world['employee'])->callAction([$open, 'cancelLeave'])->assertNotified('Leave cancelled');
    expect($page->instance()->mountedActions)->toBe([])
        ->and($request->refresh()->status)->toBe(LeaveStatus::Cancelled)
        ->and(lcEverything($page))->toBe('');
});

it('BR-20 offers no decision on your own request, and D-44 no cancel once the leave is taken', function (): void {
    $world = lcWorld();
    $hr = lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr);
    $own = lcLeave($hr, $world['annual'], '2026-06-10', status: LeaveStatus::Pending);
    $taken = lcLeave($world['employee'], $world['annual'], '2026-06-02', status: LeaveStatus::Taken);

    lcPage($hr)->assertActionHidden([TestAction::make('leaveDetails')->arguments(['id' => $own->id]), 'hrApprove'])
        ->assertActionVisible([TestAction::make('leaveDetails')->arguments(['id' => $own->id]), 'cancelLeave']);

    lcPage($world['employee'])->assertActionHidden([TestAction::make('leaveDetails')->arguments(['id' => $taken->id]), 'cancelLeave']);
});

it('shows each approval stage with who decided and why, and the decision note', function (): void {
    $manager = User::factory()->withRole(RoleName::Admin)->create(['name' => 'Mia', 'surname' => 'Manager']);
    $world = leaveSetup(manager: $manager);
    grantDays($world['employee'], $world['annual'], '10.0');
    $request = lcLeave($world['employee'], $world['annual'], '2026-06-10', status: LeaveStatus::Pending);
    resolve(DecideLeave::class)->handle($manager, $request, ApprovalStage::Manager, ApprovalDecision::Rejected, 'Release week');

    $page = lcPage($world['employee'])->mountAction('leaveDetails', ['id' => $request->id]);

    expect(lcDetails($page))->toContain('Status Rejected')->toContain('Manager: Rejected by Mia Manager “Release week”')->toContain('Hr: waiting')
        ->toContain('Decision note Release week');
});

it('keeps the calendar helper methods out of reach of the browser', function (): void {
    $world = lcWorld();
    $this->actingAs($world['employee']);
    $html = $this->get('/app/leave-requests?tab=calendar')->assertOk()->getContent();

    foreach (['calendar', 'calendarStart', 'isCalendarActive'] as $method) {
        livewireUpdate((string) $html, 'ManageLeaveRequests', $method)->assertForbidden();
    }

    livewireUpdate((string) $html, 'ManageLeaveRequests', 'nextCalendarMonth')->assertOk();
});

it('NFR-09 runs the same number of queries however many people are on leave', function (): void {
    $world = lcWorld();
    $hr = lcPerson($world, 'Hana', 'Hr', role: RoleName::Hr);
    $queries = function () use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        LeaveCalendar::month($hr, CarbonImmutable::parse('2026-06-01'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $add = function (int $people) use ($world): void {
        foreach (range(1, $people) as $i) {
            $person = lcPerson($world, 'Person', (string) $i, ['country_id' => Country::factory()->create()->id]);
            BankHoliday::factory()->create(['country_id' => $person->country_id, 'start_date' => '2026-06-10', 'end_date' => '2026-06-10']);
            lcLeave($person, $world['annual'], '2026-06-0'.min($i, 9), status: $i % 2 === 0 ? LeaveStatus::Pending : LeaveStatus::Approved);
        }
    };

    $add(2);
    $queries(); // warm up what the user model caches
    $few = $queries();

    $add(8);

    expect($queries())->toBe($few);
});
