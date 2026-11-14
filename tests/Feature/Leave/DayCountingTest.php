<?php

declare(strict_types=1);

use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Models\BankHoliday;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

// 2026-06-01 is a Monday.

function charged(array $setup, string $from, string $to, DayPart $start = DayPart::Full, DayPart $end = DayPart::Full): string
{
    return Days::format(new LeaveSpan(Date::parse($from), Date::parse($to), $start, $end)->chargedTenths($setup['country']));
}

it('BR-16 FR-29 counts only working days: a Mon to Fri request over a Wednesday holiday is 4.0', function (): void {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-03', 'end_date' => '2026-06-03']);

    expect(charged($setup, '2026-06-01', '2026-06-05'))->toBe('4.0');
})->group('BR-16', 'FR-29');

it('BR-16 skips the weekend and a holiday that falls on one has no effect (D-08)', function (): void {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-06', 'end_date' => '2026-06-06']);

    expect(charged($setup, '2026-05-29', '2026-06-01'))->toBe('2.0')   // Fri to Mon
        ->and(charged($setup, '2026-06-01', '2026-06-07'))->toBe('5.0'); // the Saturday holiday changes nothing
})->group('BR-16', 'D-08');

it("BR-16 only skips the holidays of the employee's own country and honours a holiday range", function (): void {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['start_date' => '2026-06-02', 'end_date' => '2026-06-02']);                                                // another country
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-04', 'end_date' => '2026-06-05']);        // Thu and Fri

    expect(charged($setup, '2026-06-01', '2026-06-05'))->toBe('3.0');
})->group('BR-16', 'BR-25');

it("BR-16 follows the country's working week", function (): void {
    $setup = leaveSetup();
    $setup['country']->update(['working_days' => [1, 2, 3, 4, 5, 6]]);

    expect(charged($setup, '2026-06-01', '2026-06-07'))->toBe('6.0');
})->group('BR-16', 'D-08');

it('BR-17 counts half days on the first and last day', function (): void {
    $setup = leaveSetup();

    expect(charged($setup, '2026-06-01', '2026-06-03', DayPart::Pm, DayPart::Am))->toBe('2.0')
        ->and(charged($setup, '2026-06-01', '2026-06-01', DayPart::Am))->toBe('0.5')
        ->and(charged($setup, '2026-06-01', '2026-06-01', DayPart::Pm, DayPart::Am))->toBe('0.5'); // a single day carries one part
})->group('BR-17');

it('BR-16 never trusts a client supplied number of days', function (): void {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');

    $request = resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', '2026-06-05'));

    expect($request->days_requested)->toBe('5.0');
})->group('BR-16');

it('refuses dates without a working day, backwards dates and absurd spans', function (): void {
    $setup = leaveSetup();

    expect(fn () => resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-06-06', '2026-06-07')))
        ->toThrow(ValidationException::class, 'no working days')
        ->and(fn () => resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-06-05', '2026-06-01')))
        ->toThrow(ValidationException::class, 'before the start')
        ->and(fn () => resolve(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-01-01', '2028-01-01')))
        ->toThrow(ValidationException::class, 'more than');
})->group('BR-16', 'D-42');

it('BR-16 BR-17 chargedParts lists the days chargedTenths charges, so a calendar never disagrees with the balance', function (string $from, string $to, DayPart $start, DayPart $end, array $expected): void {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-03', 'end_date' => '2026-06-03']);

    $span = new LeaveSpan(Date::parse($from), Date::parse($to), $start, $end);
    $parts = $span->chargedParts($setup['country']->working_days, ['2026-06-03' => true]);

    $tenths = array_sum(array_map(fn (array $dayParts): int => count($dayParts) === 2 ? 10 : 5, $parts));

    expect($parts)->toBe($expected)
        ->and($tenths)->toBe($span->chargedTenths($setup['country']));
})->with([
    'a weekend is not charged' => ['2026-05-29', '2026-06-01', DayPart::Full, DayPart::Full, ['2026-05-29' => ['am', 'pm'], '2026-06-01' => ['am', 'pm']]],
    'a holiday is not charged' => ['2026-06-02', '2026-06-04', DayPart::Full, DayPart::Full, ['2026-06-02' => ['am', 'pm'], '2026-06-04' => ['am', 'pm']]],
    'a half first and last day' => ['2026-06-01', '2026-06-02', DayPart::Pm, DayPart::Am, ['2026-06-01' => ['pm'], '2026-06-02' => ['am']]],
    'a single half day' => ['2026-06-01', '2026-06-01', DayPart::Am, DayPart::Pm, ['2026-06-01' => ['am']]],
    'a span without a working day' => ['2026-06-06', '2026-06-07', DayPart::Full, DayPart::Full, []],
    'a half day on a holiday' => ['2026-06-03', '2026-06-03', DayPart::Am, DayPart::Full, []],
])->group('BR-16', 'BR-17');

it("BR-16 chargedParts follows the working week it is given, not the app's default", function (): void {
    $span = new LeaveSpan(Date::parse('2026-06-05'), Date::parse('2026-06-08'));

    expect(array_keys($span->chargedParts([1, 2, 3, 4, 5, 6], [])))->toBe(['2026-06-05', '2026-06-06', '2026-06-08'])
        ->and(array_keys($span->chargedParts([2, 3, 4, 5, 6, 7], [])))->toBe(['2026-06-05', '2026-06-06', '2026-06-07']);
})->group('BR-16', 'D-08');
