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
 * A holiday on a weekend is observed on the following working day, and the app never shifts holidays
 * itself (D-08, BR-25), so every observed day is its own row. Idempotent: holidays are keyed on country and date.
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
            ['2026-03-16', 'Summer Day (observed)', null],
            ['2026-03-20', 'Eid al-Fitr', self::LUNAR_NOTE],
            ['2026-03-22', 'Nevruz Day', null],
            ['2026-03-23', 'Nevruz Day (observed)', null],
            ['2026-04-05', 'Catholic Easter', null],
            ['2026-04-06', 'Catholic Easter (observed)', null],
            ['2026-04-12', 'Orthodox Easter', null],
            ['2026-04-13', 'Orthodox Easter (observed)', null],
            ['2026-05-01', "International Workers' Day", null],
            ['2026-05-27', 'Eid al-Adha', self::LUNAR_NOTE],
            ['2026-09-05', "Saint Teresa's Canonisation Day", null],
            ['2026-09-07', "Saint Teresa's Canonisation Day (observed)", null],
            ['2026-11-22', 'Alphabet Day', null],
            ['2026-11-23', 'Alphabet Day (observed)', null],
            ['2026-11-28', 'Flag and Independence Day', null],
            ['2026-11-30', 'Flag and Independence Day (observed)', null],
            ['2026-11-29', 'Liberation Day', null],
            ['2026-12-01', 'Liberation Day (observed)', null],
            ['2026-12-08', 'National Youth Day', null],
            ['2026-12-25', 'Christmas Day', null],
        ],
    ];

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
                    ['name' => $name, 'end_date' => $date, 'description' => $description],
                );
            }
        }
    }
}
