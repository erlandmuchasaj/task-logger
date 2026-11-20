<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Enums\Proration;
use App\Domain\MasterData\Models\BankHoliday;
use App\Domain\MasterData\Models\Country;
use App\Domain\MasterData\Models\CountryLeavePolicy;
use App\Domain\MasterData\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Albania's 22-day annual leave policy and its official bank holidays (Bank of Albania schedule).
 * Only the holidays themselves are rows. A holiday on a weekend is observed on the next free working day, and the
 * app works that day out (D-62), so there is no "(observed)" row: it would be shifted a second time.
 * Idempotent: holidays are keyed on country and date.
 */
final class AlbaniaLeaveSeeder extends Seeder
{
    private const string LUNAR_NOTE = 'The date may change according to the lunar calendar.';

    /**
     * Holidays by year as [date, name, description]. Add a year when the Bank of Albania publishes it.
     *
     * @var array<int, list<array{0: string, 1: string, 2: string|null}>>
     */
    private const array HOLIDAYS = [
        2026 => [
            ['2026-01-01', "New Year's Day", null],
            ['2026-01-02', "New Year's Day", null],
            ['2026-03-14', 'Summer Day', null],
            ['2026-03-20', 'Eid al-Fitr', self::LUNAR_NOTE],
            ['2026-03-22', 'Nevruz Day', null],
            ['2026-04-05', 'Catholic Easter', null],
            ['2026-04-12', 'Orthodox Easter', null],
            ['2026-05-01', "International Workers' Day", null],
            ['2026-05-27', 'Eid al-Adha', self::LUNAR_NOTE],
            ['2026-09-05', "Saint Teresa's Canonisation Day", null],
            ['2026-11-22', 'Alphabet Day', null],
            ['2026-11-28', 'Flag and Independence Day', null],
            ['2026-11-29', 'Liberation Day', null],
            ['2026-12-08', 'National Youth Day', null],
            ['2026-12-25', 'Christmas Day', null],
        ],
    ];

    /**
     * Fixed-date holidays (month-day) that repeat every year (D-59). Easter and Eid move, so they stay one-off.
     *
     * @var list<string>
     */
    private const array REPEATING = ['01-01', '01-02', '03-14', '03-22', '05-01', '09-05', '11-22', '11-28', '11-29', '12-08', '12-25'];

    public function run(): void
    {
        $albania = Country::query()->where('code', 'AL')->first();
        $annualLeave = LeaveType::query()->where('name', 'annual_leave')->first();

        if ($albania === null || $annualLeave === null) {
            $this->command?->warn('AlbaniaLeaveSeeder skipped: run CountrySeeder and MasterDataSeeder first.');

            return;
        }

        CountryLeavePolicy::query()->firstOrCreate(
            ['country_id' => $albania->id, 'leave_type_id' => $annualLeave->id, 'effective_from' => '2026-01-01'],
            ['days_per_year' => '22.0', 'proration' => Proration::ByStartDate, 'carry_over_enabled' => false],
        );

        foreach (self::HOLIDAYS as $holidays) {
            foreach ($holidays as [$date, $name, $description]) {
                BankHoliday::query()->firstOrCreate(
                    ['country_id' => $albania->id, 'start_date' => $date],
                    ['name' => $name, 'end_date' => $date, 'description' => $description, 'repeats_yearly' => in_array(mb_substr($date, 5), self::REPEATING, true)],
                );
            }
        }
    }
}
