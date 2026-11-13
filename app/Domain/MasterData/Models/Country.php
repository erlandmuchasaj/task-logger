<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<int> $working_days
 */
#[Fillable(['name', 'code', 'prefix', 'timezone', 'currency_code', 'working_days'])]
#[UseFactory(CountryFactory::class)]
final class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /**
     * ISO weekdays, 1 = Monday. Default Monday to Friday (D-08), configurable per country.
     *
     * @return list<int>
     */
    public static function defaultWorkingDays(): array
    {
        /** @var list<int> $days */
        $days = config('task-logger.default_working_days');

        return $days;
    }

    /** @return HasMany<CountryLeavePolicy, $this> */
    public function leavePolicies(): HasMany
    {
        return $this->hasMany(CountryLeavePolicy::class);
    }

    /** @return HasMany<BankHoliday, $this> */
    public function bankHolidays(): HasMany
    {
        return $this->hasMany(BankHoliday::class);
    }

    public function isWorkingDay(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->working_days, true);
    }

    protected static function booted(): void
    {
        self::creating(function (self $country): void {
            $country->working_days ??= self::defaultWorkingDays();
        });
    }

    protected function casts(): array
    {
        return ['working_days' => 'array'];
    }
}
