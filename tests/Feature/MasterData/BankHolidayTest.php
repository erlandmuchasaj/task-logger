<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\RoleName;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Support\BankHolidays;
use App\Domain\MasterData\Support\HolidayOccurrence;
use App\Filament\Resources\BankHolidays\Pages\ManageBankHolidays;
use Filament\Actions\CreateAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('BR-25 superadmin and hr create a bank holiday per country', function (RoleName $role): void {
    $this->actingAs(userWithRole($role));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Labour Day',
            'country_id' => $country->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
        ])
        ->assertHasNoFormErrors();

    expect(BankHoliday::query()->where('name', 'Labour Day')->where('country_id', $country->id)->exists())->toBeTrue();
})->group('BR-25')->with([RoleName::Superadmin, RoleName::Hr]);

it('BR-25 admin, assistant and employee cannot manage bank holidays but can view them', function (RoleName $role): void {
    $user = userWithRole($role);
    $holiday = BankHoliday::factory()->create();

    expect($user->can('create', BankHoliday::class))->toBeFalse()
        ->and($user->can('update', $holiday))->toBeFalse()
        ->and($user->can('delete', $holiday))->toBeFalse()
        ->and($user->can('viewAny', BankHoliday::class))->toBeTrue(); // bank_holidays.view: calendar
})->group('BR-25')->with([RoleName::Admin, RoleName::Assistant, RoleName::Employee]);

it('BR-25 rejects an end date before the start date, in the form and in the database', function (): void {
    $this->actingAs(userWithRole(RoleName::Hr));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Bad range', 'country_id' => $country->id,
            'start_date' => '2026-05-10', 'end_date' => '2026-05-01',
        ])
        ->assertHasFormErrors(['end_date']);

    expect(fn () => BankHoliday::factory()->create(['start_date' => '2026-05-10', 'end_date' => '2026-05-01']))
        ->toThrow(QueryException::class);
})->group('BR-25');

/**
 * The observed days of a country in the window, as dates, oldest first.
 *
 * @return list<string>
 */
function observedDates(Country $country, string $from, string $to): array
{
    return BankHolidays::occurrences([(int) $country->getKey()], $from, $to)
        ->filter(fn (HolidayOccurrence $occurrence): bool => $occurrence->observed)
        ->map(fn (HolidayOccurrence $occurrence): string => $occurrence->start->toDateString())
        ->values()->all();
}

it('BR-25 / D-62 a weekend holiday stays where it falls in a country that does not move them', function (): void {
    $country = Country::factory()->create(); // Stays is the default
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Summer Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);

    $occurrences = BankHolidays::occurrences([(int) $country->getKey()], '2026-03-01', '2026-03-31');

    expect($occurrences)->toHaveCount(1)
        ->and($occurrences->sole()->observed)->toBeFalse()
        ->and(array_keys(BankHolidays::dates($country, '2026-03-01', '2026-03-31')))->toBe(['2026-03-14']); // a Saturday, as entered
})->group('BR-25', 'D-62');

it('D-62 14 March is observed on Monday the 16th in 2026, on Monday the 15th in 2027 and not at all in 2028', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'name' => 'Summer Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);
    $march = fn (int $year): array => array_keys(BankHolidays::dates($country, $year.'-03-01', $year.'-03-31'));

    expect($march(2026))->toBe(['2026-03-14', '2026-03-16']) // Saturday, so Monday
        ->and($march(2027))->toBe(['2027-03-14', '2027-03-15']) // Sunday, so Monday
        ->and($march(2028))->toBe(['2028-03-14']); // a Tuesday: nothing to observe

    $observed = BankHolidays::occurrences([(int) $country->getKey()], '2026-03-01', '2026-03-31')->last();
    expect($observed?->label())->toBe('Summer Day (observed)')->and($observed?->start->toDateString())->toBe('2026-03-16');
})->group('D-62');

it('D-62 a weekend day takes the next free working day, never a holiday or a day already taken', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'name' => 'Flag Day', 'start_date' => '2026-11-28', 'end_date' => '2026-11-28']);
    BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'name' => 'Liberation Day', 'start_date' => '2026-11-29', 'end_date' => '2026-11-29']);

    expect(observedDates($country, '2026-11-01', '2026-12-31'))->toBe(['2026-11-30', '2026-12-01']) // Saturday and Sunday: Monday and Tuesday
        ->and(observedDates($country, '2027-11-01', '2027-12-31'))->toBe(['2027-11-30']) // Sunday 28th: Monday 29th is Liberation Day, so Tuesday
        ->and(observedDates($country, '2028-11-01', '2028-12-31'))->toBe([]); // Tuesday and Wednesday

    $labels = BankHolidays::occurrences([(int) $country->getKey()], '2026-11-30', '2026-12-01')->map(fn (HolidayOccurrence $o): string => $o->label())->all();
    expect($labels)->toBe(['Flag Day (observed)', 'Liberation Day (observed)']);
})->group('D-62');

it('D-62 two holidays on the same Sunday share one observed Monday', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Catholic Easter', 'start_date' => '2025-04-20', 'end_date' => '2025-04-20']);
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Orthodox Easter', 'start_date' => '2025-04-20', 'end_date' => '2025-04-20']);

    $labels = BankHolidays::occurrences([(int) $country->getKey()], '2025-04-21', '2025-04-30')->map(fn (HolidayOccurrence $o): string => $o->label())->all();

    expect(array_keys(BankHolidays::dates($country, '2025-04-01', '2025-04-30')))->toBe(['2025-04-20', '2025-04-21']) // not Tuesday as well
        ->and($labels)->toBe(['Catholic Easter (observed)', 'Orthodox Easter (observed)']);
})->group('D-62');

it('D-62 finds an observed day inside a window that starts after its holiday, and not one past the window', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Summer Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);

    expect(array_keys(BankHolidays::dates($country, '2026-03-16', '2026-03-20')))->toBe(['2026-03-16'])   // the holiday is before the window
        ->and(array_keys(BankHolidays::dates($country, '2026-03-14', '2026-03-15')))->toBe(['2026-03-14']) // the Monday is after it
        ->and(BankHolidays::occurrences([(int) $country->getKey()], '2026-03-17', '2026-03-31'))->toHaveCount(0);
})->group('D-62');

it('D-62 a day the country works is an ordinary holiday, and only a non-working day moves', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create(['working_days' => [1, 2, 3, 4, 5, 6]]);
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Saturday Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Sunday Day', 'start_date' => '2026-03-22', 'end_date' => '2026-03-22']);

    expect(observedDates($country, '2026-03-01', '2026-03-31'))->toBe(['2026-03-23']);
})->group('D-62');

it('D-62 every weekend day of a holiday range is observed once the range is over', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Long Break', 'start_date' => '2026-03-13', 'end_date' => '2026-03-16']); // Friday to Monday

    // Monday the 16th is itself a holiday, so Saturday and Sunday are observed on Tuesday and Wednesday.
    expect(observedDates($country, '2026-03-01', '2026-03-31'))->toBe(['2026-03-17', '2026-03-18']);
})->group('D-62');

it('D-62 a range that began before the look-back still pushes its weekends past its end', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create();
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Shutdown', 'start_date' => '2026-01-05', 'end_date' => '2026-02-20']); // 12 weekend days

    expect(observedDates($country, '2026-03-02', '2026-03-31'))->toBe(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-09', '2026-03-10']);
})->group('D-62');

it('D-62 a country with no working day cannot loop, and observes nothing', function (): void {
    $country = Country::factory()->movesWeekendHolidays()->create(['working_days' => []]);
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Any Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);

    expect(observedDates($country, '2026-03-01', '2026-03-31'))->toBe([]);
})->group('D-62');

it('D-62 only the countries that move weekend holidays get an observed day', function (): void {
    $moving = Country::factory()->movesWeekendHolidays()->create();
    $staying = Country::factory()->create();

    foreach ([$moving, $staying] as $country) {
        BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Summer Day', 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);
    }

    $all = BankHolidays::occurrences(null, '2026-03-01', '2026-03-31');

    expect($all)->toHaveCount(3)
        ->and($all->filter(fn (HolidayOccurrence $o): bool => $o->observed)->map(fn (HolidayOccurrence $o): int => $o->countryId)->values()->all())->toBe([(int) $moving->getKey()]);
})->group('D-62');

it('D-62 asks the database twice, however many holidays and countries there are', function (): void {
    foreach (range(1, 4) as $i) {
        $country = Country::factory()->movesWeekendHolidays()->create();
        BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'start_date' => '2026-03-14', 'end_date' => '2026-03-14']);
        BankHoliday::factory()->create(['country_id' => $country->id, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05']);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    BankHolidays::occurrences(null, '2026-03-01', '2026-04-30');
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toHaveCount(2);
})->group('D-62', 'NFR-09');

it('D-59 hr creates a repeating holiday through the form, with a last year', function (): void {
    $this->actingAs(userWithRole(RoleName::Hr));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Independence Day', 'country_id' => $country->id,
            'start_date' => '2026-11-28', 'end_date' => '2026-11-28',
            'repeats_yearly' => true, 'repeat_until_year' => 2035,
        ])
        ->assertHasNoFormErrors();

    $holiday = BankHoliday::query()->where('name', 'Independence Day')->firstOrFail();
    expect($holiday->repeats_yearly)->toBeTrue()->and($holiday->repeat_until_year)->toBe(2035);
})->group('D-59');

it('D-59 forgets the last year when the holiday does not repeat', function (): void {
    $this->actingAs(userWithRole(RoleName::Hr));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Eid', 'country_id' => $country->id,
            'start_date' => '2026-03-20', 'end_date' => '2026-03-20',
            'repeats_yearly' => false, 'repeat_until_year' => 2035,
        ])
        ->assertHasNoFormErrors();

    expect(BankHoliday::query()->where('name', 'Eid')->firstOrFail()->repeat_until_year)->toBeNull();
})->group('D-59');

it('D-59 refuses a repeating holiday on 29 February or a year long range, in the form and in the database', function (): void {
    $this->actingAs(userWithRole(RoleName::Hr));
    $country = Country::factory()->create();

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Leap Day', 'country_id' => $country->id,
            'start_date' => '2028-02-29', 'end_date' => '2028-02-29', 'repeats_yearly' => true,
        ])
        ->assertHasFormErrors(['repeats_yearly']);

    Livewire::test(ManageBankHolidays::class)
        ->callAction(CreateAction::class, [
            'name' => 'Whole year', 'country_id' => $country->id,
            'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'repeats_yearly' => true,
        ])
        ->assertHasFormErrors(['repeats_yearly']);

    expect(fn () => BankHoliday::factory()->repeating()->create(['start_date' => '2028-02-29', 'end_date' => '2028-02-29']))
        ->toThrow(QueryException::class)
        ->and(fn () => BankHoliday::factory()->repeating()->create(['start_date' => '2026-01-01', 'end_date' => '2027-01-01']))
        ->toThrow(QueryException::class);
})->group('D-59');

it('D-59 the database refuses a last year without the repeat flag or before the first year', function (): void {
    expect(fn () => BankHoliday::factory()->create(['repeat_until_year' => 2030]))->toThrow(QueryException::class)
        ->and(fn () => BankHoliday::factory()->repeating(2025)->create(['start_date' => '2026-05-01', 'end_date' => '2026-05-01']))
        ->toThrow(QueryException::class);
})->group('D-59');

it('D-59 an assistant or employee cannot create a repeating holiday', function (RoleName $role): void {
    $this->actingAs(userWithRole($role));

    Livewire::test(ManageBankHolidays::class)->assertActionHidden(CreateAction::class);
})->group('D-59')->with([RoleName::Assistant, RoleName::Employee]);

it('D-59 a repeating holiday returns every year from its own year on, never before', function (): void {
    $country = Country::factory()->create();
    BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'name' => 'Independence', 'start_date' => '2026-11-28', 'end_date' => '2026-11-28']);
    $ids = [(int) $country->getKey()];
    $starts = fn (string $from, string $to): array => BankHolidays::occurrences($ids, $from, $to)->map(fn ($o): string => $o->start->toDateString())->all();

    expect($starts('2027-01-01', '2027-12-31'))->toBe(['2027-11-28'])
        ->and($starts('2030-11-01', '2030-11-30'))->toBe(['2030-11-28'])
        ->and($starts('2025-01-01', '2025-12-31'))->toBe([])
        ->and($starts('2026-01-01', '2028-12-31'))->toBe(['2026-11-28', '2027-11-28', '2028-11-28']);
})->group('D-59');

it('D-59 a repeating holiday stops after its last year', function (): void {
    $country = Country::factory()->create();
    BankHoliday::factory()->repeating(2028)->create(['country_id' => $country->id, 'start_date' => '2026-11-28', 'end_date' => '2026-11-28']);
    $count = fn (string $from, string $to): int => BankHolidays::occurrences([(int) $country->getKey()], $from, $to)->count();

    expect($count('2028-01-01', '2028-12-31'))->toBe(1)
        ->and($count('2029-01-01', '2029-12-31'))->toBe(0)
        ->and($count('2035-01-01', '2035-12-31'))->toBe(0);
})->group('D-59');

it('D-59 a repeating range across New Year shows in both years, and a one-off stays one-off', function (): void {
    $country = Country::factory()->create();
    BankHoliday::factory()->repeating()->create(['country_id' => $country->id, 'name' => 'New Year', 'start_date' => '2026-12-31', 'end_date' => '2027-01-02']);
    BankHoliday::factory()->create(['country_id' => $country->id, 'name' => 'Eid', 'start_date' => '2026-03-20', 'end_date' => '2026-03-20']);

    $dates = BankHolidays::dates($country, '2028-01-01', '2028-01-31');
    $later = BankHolidays::dates($country, '2028-12-30', '2029-01-03');

    expect(array_keys($dates))->toBe(['2028-01-01', '2028-01-02'])
        ->and(array_keys($later))->toBe(['2028-12-31', '2029-01-01', '2029-01-02'])
        ->and(BankHolidays::dates($country, '2027-03-01', '2027-03-31'))->toBe([]);
})->group('D-59');

it('D-59 a repeating holiday only counts for its own country', function (): void {
    $country = Country::factory()->create();
    BankHoliday::factory()->repeating()->create(['start_date' => '2026-11-28', 'end_date' => '2026-11-28']); // another country

    expect(BankHolidays::dates($country, '2027-11-01', '2027-11-30'))->toBe([]);
})->group('D-59');
