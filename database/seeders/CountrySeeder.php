<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\MasterData\Models\Country;
use Illuminate\Database\Seeder;

/** The two countries SPEC 5.1 names. Idempotent on `code`; working days default to Monday to Friday. */
final class CountrySeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['Albania', 'AL', '+355', 'Europe/Tirane', 'ALL'],
            ['Kosovo', 'XK', '+383', 'Europe/Belgrade', 'EUR'],
        ];

        foreach ($countries as [$name, $code, $prefix, $timezone, $currency]) {
            Country::firstOrCreate(['code' => $code], [
                'name' => $name,
                'prefix' => $prefix,
                'timezone' => $timezone,
                'currency_code' => $currency,
            ]);
        }
    }
}
