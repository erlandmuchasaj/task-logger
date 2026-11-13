<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Leave\Actions\ExpireCarryOver;
use App\Domain\Leave\Actions\GrantAnnualLeave;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\Entitlement;
use App\Domain\Leave\Support\LeaveBalance;
use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\People\Actions\CreateEmployee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

afterEach(fn () => Date::setTestNow());

// Albania is UTC+1 in winter and UTC+2 in summer (Europe/Tirane).

function entries(User $user, ?LedgerEntryType $type = null)
{
    return LeaveLedgerEntry::query()->where('user_id', $user->id)->when($type, fn ($q) => $q->where('entry_type', $type))->orderBy('id')->get();
}

it('BR-18 FR-32 writes one grant per user, type and year and running the job twice adds nothing', function (): void {
    $setup = leaveSetup();
    Date::setTestNow('2026-01-01 10:00:00');

    expect(resolve(GrantAnnualLeave::class)->handle())->toBe(1)
        ->and(resolve(GrantAnnualLeave::class)->handle())->toBe(0)
        ->and(entries($setup['employee']))->toHaveCount(1)
        ->and(entries($setup['employee'])->first())->entry_type->toBe(LedgerEntryType::Grant)->days->toBe('20.0')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(200);
})->group('BR-18', 'FR-32', 'NFR-13');

it("BR-18 BR-34 the new year starts at midnight in the employee's own timezone", function (): void {
    $setup = leaveSetup();

    Date::setTestNow('2025-12-31 22:30:00');   // 23:30 on 31 December in Tirane: it is still 2025
    resolve(GrantAnnualLeave::class)->handle();
    expect(entries($setup['employee'])->pluck('effective_on')->map->toDateString()->all())->toBe(['2025-01-01']);

    Date::setTestNow('2025-12-31 23:30:00');   // 00:30 on 1 January in Tirane
    resolve(GrantAnnualLeave::class)->handle();
    expect(entries($setup['employee'], LedgerEntryType::Grant)->pluck('effective_on')->map->toDateString()->all())->toBe(['2025-01-01', '2026-01-01']);
})->group('BR-18', 'BR-34');

it('BR-18 a joiner gets a prorated grant on the start date, rounded to the nearest half day', function (string $start, string $expected): void {
    $setup = leaveSetup(startDate: $start);
    Date::setTestNow($start.' 08:00:00');

    resolve(GrantAnnualLeave::class)->handle();

    expect(entries($setup['employee'])->sole())->days->toBe($expected)->effective_on->toDateString()->toBe($start);
})->with([
    'joined 1 July: 20 x 184/365 = 10.08' => ['2026-07-01', '10.0'],
    'joined 15 March: 20 x 292/365 = 16.0' => ['2026-03-15', '16.0'],
    'joined 1 October: 20 x 92/365 = 5.04' => ['2026-10-01', '5.0'],
    'joined 20 October: 20 x 73/365 = 4.0' => ['2026-10-20', '4.0'],
    'joined 1 January: the whole year' => ['2026-01-01', '20.0'],
])->group('BR-18', 'FR-32');

it('BR-18 rounds a prorated grant to the nearest half day, upwards at the midpoint', function (): void {
    $policy = CountryLeavePolicy::factory()->make(['days_per_year' => '21.0', 'proration' => Proration::ByStartDate]);

    // 21 x 100 / 365 = 5.75 days: exactly between 5.5 and 6.0, so up.
    expect(Days::format(Entitlement::grantTenths($policy, Date::parse('2026-09-22'), true)))->toBe('6.0');
})->group('BR-18');

it('BR-18 proration none gives a joiner the full year', function (): void {
    $setup = leaveSetup(startDate: '2026-07-01');
    CountryLeavePolicy::query()->where('country_id', $setup['country']->id)->update(['proration' => Proration::None]);
    Date::setTestNow('2026-07-01 08:00:00');

    resolve(GrantAnnualLeave::class)->handle();

    expect(entries($setup['employee'])->sole()->days)->toBe('20.0');
})->group('BR-18');

it('BR-18 grants nothing before the start date, to a missing country or policy, or to a banned user', function (): void {
    $setup = leaveSetup(startDate: '2026-07-01');
    $banned = User::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2024-01-01']);
    $banned->ban();
    User::factory()->create(['country_id' => null, 'start_date' => '2024-01-01']);
    $other = User::factory()->create(['country_id' => Country::factory()->create()->id, 'start_date' => '2024-01-01']);

    Date::setTestNow('2026-06-01 10:00:00');

    expect(resolve(GrantAnnualLeave::class)->handle())->toBe(0)
        ->and(LeaveLedgerEntry::query()->count())->toBe(0)
        ->and($other->id)->toBeInt();
})->group('BR-18');

it('BR-18 FR-06 uses the policy that is active on the grant date', function (): void {
    $setup = leaveSetup();
    CountryLeavePolicy::factory()->create(['country_id' => $setup['country']->id, 'leave_type_id' => $setup['annual']->id, 'days_per_year' => '25.0', 'effective_from' => '2026-01-01']);
    Date::setTestNow('2026-01-01 10:00:00');

    resolve(GrantAnnualLeave::class)->handle();

    expect(entries($setup['employee'], LedgerEntryType::Grant)->sole()->days)->toBe('25.0');
})->group('BR-18', 'FR-06');

it('BR-18 D-08 with carry-over off the unused balance of last year expires at the new year', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0', '2025-01-01');
    LeaveLedgerEntry::query()->create(['user_id' => $setup['employee']->id, 'leave_type_id' => $setup['annual']->id, 'entry_type' => LedgerEntryType::Deduction, 'days' => '-5.0', 'effective_on' => '2025-06-01']);
    Date::setTestNow('2026-01-01 10:00:00');

    resolve(GrantAnnualLeave::class)->handle();

    expect(entries($setup['employee'], LedgerEntryType::Expiry)->sole()->days)->toBe('-15.0')
        ->and(entries($setup['employee'], LedgerEntryType::CarryOver))->toHaveCount(0)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(200);
})->group('BR-18', 'D-08');

it('BR-18 carry-over follows the country policy: capped at its maximum, expiring after its months', function (): void {
    $setup = leaveSetup();
    CountryLeavePolicy::factory()->create([
        'country_id' => $setup['country']->id, 'leave_type_id' => $setup['annual']->id, 'days_per_year' => '20.0', 'effective_from' => '2026-01-01',
        'carry_over_enabled' => true, 'carry_over_max_days' => '5.0', 'carry_over_expires_after_months' => 3,
    ]);
    grantDays($setup['employee'], $setup['annual'], '15.0', '2025-01-01');
    Date::setTestNow('2026-01-01 10:00:00');

    resolve(GrantAnnualLeave::class)->handle();

    $carry = entries($setup['employee'], LedgerEntryType::CarryOver)->sole();

    expect($carry)->days->toBe('5.0')->expires_on->toDateString()->toBe('2026-04-01')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(250);   // 5 carried + 20 granted

    Date::setTestNow('2026-03-31 10:00:00');
    expect(resolve(ExpireCarryOver::class)->handle())->toBe(0);

    Date::setTestNow('2026-04-01 10:00:00');
    expect(resolve(ExpireCarryOver::class)->handle())->toBe(1)
        ->and(resolve(ExpireCarryOver::class)->handle())->toBe(0)    // idempotent
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(200);
})->group('BR-18', 'FR-32');

it('BR-18 days used since the carry-over come out of the carried part first', function (string $used, int $expectedBalance, int $expiryEntries): void {
    $setup = leaveSetup();
    CountryLeavePolicy::factory()->create([
        'country_id' => $setup['country']->id, 'leave_type_id' => $setup['annual']->id, 'days_per_year' => '20.0', 'effective_from' => '2026-01-01',
        'carry_over_enabled' => true, 'carry_over_max_days' => '5.0', 'carry_over_expires_after_months' => 3,
    ]);
    grantDays($setup['employee'], $setup['annual'], '15.0', '2025-01-01');
    Date::setTestNow('2026-01-01 10:00:00');
    resolve(GrantAnnualLeave::class)->handle();
    LeaveLedgerEntry::query()->create(['user_id' => $setup['employee']->id, 'leave_type_id' => $setup['annual']->id, 'entry_type' => LedgerEntryType::Deduction, 'days' => $used, 'effective_on' => '2026-02-10']);

    Date::setTestNow('2026-04-02 10:00:00');
    resolve(ExpireCarryOver::class)->handle();

    expect(entries($setup['employee'], LedgerEntryType::Expiry)->count() - 1)->toBe($expiryEntries)   // minus the year-end expiry
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe($expectedBalance);
})->with([
    'used 3 of 5: 2 expire' => ['-3.0', 200, 1],
    'used 8: carried part fully used, nothing expires' => ['-8.0', 170, 0],
])->group('BR-18');

it('NFR-13 the database itself refuses a second grant for the same day', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0', '2026-01-01');

    expect(fn (): LeaveLedgerEntry => grantDays($setup['employee'], $setup['annual'], '20.0', '2026-01-01'))->toThrow(QueryException::class);
    expect(grantDays($setup['employee'], $setup['annual'], '5.0', '2026-01-02'))->toBeInstanceOf(LeaveLedgerEntry::class);
})->group('NFR-13');

it('W-01 step 4 creating an employee whose start date has come writes the grant for the year of joining', function (): void {
    Notification::fake();
    $setup = leaveSetup();
    Date::setTestNow('2026-07-01 08:00:00');

    $employee = resolve(CreateEmployee::class)->handle(userWithRole(RoleName::Superadmin), employeeData(['startDate' => Date::parse('2026-07-01')]));

    expect(entries($employee)->sole())->days->toBe('10.0')->entry_type->toBe(LedgerEntryType::Grant);
})->group('W-01', 'BR-18');

function carryPolicy(array $setup, int $months, ?string $cap = null): void
{
    CountryLeavePolicy::factory()->create([
        'country_id' => $setup['country']->id, 'leave_type_id' => $setup['annual']->id, 'days_per_year' => '20.0', 'effective_from' => '2025-01-01',
        'carry_over_enabled' => true, 'carry_over_max_days' => $cap, 'carry_over_expires_after_months' => $months,
    ]);
}

/** One scheduler pass on a day, in the order of routes/console.php. */
function runLeaveJobs(string $day): void
{
    Date::setTestNow($day.' 10:00:00');
    resolve(GrantAnnualLeave::class)->handle();
    resolve(ExpireCarryOver::class)->handle();
}

function expiriesOn(array $setup, string $day)
{
    return entries($setup['employee'], LedgerEntryType::Expiry)->filter(fn ($entry): bool => $entry->effective_on->toDateString() === $day)->values();
}

function takeDays(array $setup, string $days, string $on): void
{
    LeaveLedgerEntry::query()->create(['user_id' => $setup['employee']->id, 'leave_type_id' => $setup['annual']->id, 'entry_type' => LedgerEntryType::Deduction, 'days' => $days, 'effective_on' => $on]);
}

it('BR-18 a 12-month carry-over that is unused on its expiry date is not renewed by the next year-end', function (): void {
    $setup = leaveSetup();
    carryPolicy($setup, 12);

    foreach (['2025-01-01', '2026-01-01', '2027-01-01'] as $day) {
        runLeaveJobs($day);
    }

    $carries = entries($setup['employee'], LedgerEntryType::CarryOver);

    // 2026 carried the 20 days of 2025 until 2027-01-01. At 2027-01-01 those expire and only the 2026 grant is carried.
    expect($carries)->toHaveCount(2)
        ->and($carries->last())->days->toBe('20.0')->expires_on->toDateString()->toBe('2028-01-01')
        ->and(expiriesOn($setup, '2027-01-01')->sole()->days)->toBe('-40.0')   // all of it expires; only 20 is carried again
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(400);   // 2026 grant carried (20) + 2027 grant (20)

    expect(resolve(ExpireCarryOver::class)->handle())->toBe(0)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(400);
})->group('BR-18');

it('BR-18 an 18-month carry-over keeps its own expiry date and expires once, for its own days', function (): void {
    $setup = leaveSetup();
    carryPolicy($setup, 18);

    foreach (['2025-01-01', '2026-01-01', '2027-01-01'] as $day) {
        runLeaveJobs($day);
    }

    $carries = entries($setup['employee'], LedgerEntryType::CarryOver);

    expect($carries)->toHaveCount(2)
        ->and($carries->first())->expires_on->toDateString()->toBe('2027-07-01')
        ->and($carries->last())->days->toBe('20.0')->expires_on->toDateString()->toBe('2028-07-01')
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(600);   // tranche 2026 + tranche 2027 + grant 2027

    runLeaveJobs('2027-07-01');

    $expiry = expiriesOn($setup, '2027-07-01')->sole();

    expect($expiry->days)->toBe('-20.0')
        ->and(resolve(ExpireCarryOver::class)->handle())->toBe(0)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(400);
})->group('BR-18');

it('BR-18 days taken while two carry-overs overlap come out of the older one first', function (): void {
    $setup = leaveSetup();
    carryPolicy($setup, 18);

    foreach (['2025-01-01', '2026-01-01', '2027-01-01'] as $day) {
        runLeaveJobs($day);
    }

    takeDays($setup, '-15.0', '2027-03-01');   // all 15 come out of the 2026 tranche
    runLeaveJobs('2027-07-01');

    expect(expiriesOn($setup, '2027-07-01')->sole()->days)->toBe('-5.0');

    runLeaveJobs('2028-01-01');

    // The 2027 tranche is untouched, so it stays live and the new year carries only the 2027 grant.
    expect(entries($setup['employee'], LedgerEntryType::CarryOver)->last())->days->toBe('20.0')->expires_on->toDateString()->toBe('2029-07-01')
        ->and(expiriesOn($setup, '2028-01-01')->sole()->days)->toBe('-20.0');
})->group('BR-18');

it('BR-18 the carry-over maximum limits what is carried at once, counting tranches that are still live', function (): void {
    $setup = leaveSetup();
    carryPolicy($setup, 18, '10.0');

    foreach (['2025-01-01', '2026-01-01', '2027-01-01'] as $day) {
        runLeaveJobs($day);
    }

    // 10 days from 2026 are still live on 2027-01-01 and fill the cap, so nothing new is carried.
    expect(entries($setup['employee'], LedgerEntryType::CarryOver))->toHaveCount(1)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(300);   // live tranche 10 + grant 20

    runLeaveJobs('2027-07-01');
    expect(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(200);
})->group('BR-18');

it("BR-18 the expiry job running first on 1 January leaves that day's expiry entry to the year-end close", function (): void {
    $setup = leaveSetup();
    carryPolicy($setup, 12);

    runLeaveJobs('2025-01-01');
    runLeaveJobs('2026-01-01');

    Date::setTestNow('2027-01-01 10:00:00');
    resolve(ExpireCarryOver::class)->handle();   // before the grant job
    resolve(GrantAnnualLeave::class)->handle();

    expect(expiriesOn($setup, '2027-01-01'))->toHaveCount(1)
        ->and(LeaveBalance::balance($setup['employee'], $setup['annual']->id))->toBe(400);
})->group('BR-18', 'NFR-13');
