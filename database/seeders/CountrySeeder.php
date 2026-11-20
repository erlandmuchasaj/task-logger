<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Enums\WeekendHolidayRule;
use App\Domain\MasterData\Models\Country;
use Illuminate\Database\Seeder;

/**
 * The two countries SPEC 5.1 names. Idempotent on `code`; working days default to Monday to Friday.
 * Albania observes a weekend holiday on the next working day (D-62), Kosovo does not until HR says so.
 */
final class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['Albania', 'AL', '+355', 'Europe/Tirane', 'ALL', 'sq', WeekendHolidayRule::NextWorkingDay],
            ['Kosovo', 'XK', '+383', 'Europe/Belgrade', 'EUR', 'sq', WeekendHolidayRule::Stays],
        ];

        foreach ($countries as [$name, $code, $prefix, $timezone, $currency, $language, $weekendRule]) {
            Country::firstOrCreate(['code' => $code], [
                'name' => $name,
                'prefix' => $prefix,
                'timezone' => $timezone,
                'currency_code' => $currency,
                'language' => $language,
                'weekend_holiday_rule' => $weekendRule,
            ]);
        }
    }
}
