<?php

declare(strict_types=1);

use App\Domain\Leave\Actions\RequestLeave;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Support\Days;
use App\Domain\Leave\Support\LeaveSpan;
use App\Domain\MasterData\Models\BankHoliday;
use Illuminate\Validation\ValidationException;

// 2026-06-01 is a Monday.

function charged(array $setup, string $from, string $to, DayPart $start = DayPart::Full, DayPart $end = DayPart::Full): string
{
    return Days::format((new LeaveSpan(Carbon\Carbon::parse($from), Carbon\Carbon::parse($to), $start, $end))->chargedTenths($setup['country']));
}

it('BR-16 FR-29 counts only working days: a Mon to Fri request over a Wednesday holiday is 4.0', function () {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-03', 'end_date' => '2026-06-03']);

    expect(charged($setup, '2026-06-01', '2026-06-05'))->toBe('4.0');
})->group('BR-16', 'FR-29');

it('BR-16 skips the weekend and a holiday that falls on one has no effect (D-08)', function () {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-06', 'end_date' => '2026-06-06']);

    expect(charged($setup, '2026-05-29', '2026-06-01'))->toBe('2.0')   // Fri to Mon
        ->and(charged($setup, '2026-06-01', '2026-06-07'))->toBe('5.0'); // the Saturday holiday changes nothing
})->group('BR-16', 'D-08');

it('BR-16 only skips the holidays of the employee\'s own country and honours a holiday range', function () {
    $setup = leaveSetup();
    BankHoliday::factory()->create(['start_date' => '2026-06-02', 'end_date' => '2026-06-02']);                                                // another country
    BankHoliday::factory()->create(['country_id' => $setup['country']->id, 'start_date' => '2026-06-04', 'end_date' => '2026-06-05']);        // Thu and Fri

    expect(charged($setup, '2026-06-01', '2026-06-05'))->toBe('3.0');
})->group('BR-16', 'BR-25');

it('BR-16 follows the country\'s working week', function () {
    $setup = leaveSetup();
    $setup['country']->update(['working_days' => [1, 2, 3, 4, 5, 6]]);

    expect(charged($setup, '2026-06-01', '2026-06-07'))->toBe('6.0');
})->group('BR-16', 'D-08');

it('BR-17 counts half days on the first and last day', function () {
    $setup = leaveSetup();

    expect(charged($setup, '2026-06-01', '2026-06-03', DayPart::Pm, DayPart::Am))->toBe('2.0')
        ->and(charged($setup, '2026-06-01', '2026-06-01', DayPart::Am))->toBe('0.5')
        ->and(charged($setup, '2026-06-01', '2026-06-01', DayPart::Pm, DayPart::Am))->toBe('0.5'); // a single day carries one part
})->group('BR-17');

it('BR-16 never trusts a client supplied number of days', function () {
    $setup = leaveSetup();
    grantDays($setup['employee'], $setup['annual'], '20.0');

    $request = app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['annual'], '2026-06-01', '2026-06-05'));

    expect($request->days_requested)->toBe('5.0');
})->group('BR-16');

it('refuses dates without a working day, backwards dates and absurd spans', function () {
    $setup = leaveSetup();

    expect(fn () => app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-06-06', '2026-06-07')))
        ->toThrow(ValidationException::class, 'no working days')
        ->and(fn () => app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-06-05', '2026-06-01')))
        ->toThrow(ValidationException::class, 'before the start')
        ->and(fn () => app(RequestLeave::class)->handle($setup['employee'], leaveData($setup['unpaid'], '2026-01-01', '2028-01-01')))
        ->toThrow(ValidationException::class, 'more than');
})->group('BR-16', 'D-42');
