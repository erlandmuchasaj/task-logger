<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\BankHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A holiday on a weekend is never shifted automatically (D-08, BR-25). HR adds an observed
 * Monday as its own entry.
 */
#[Fillable(['name', 'start_date', 'end_date', 'country_id', 'description'])]
#[UseFactory(BankHolidayFactory::class)]
final class BankHoliday extends Model
{
    /** @use HasFactory<BankHolidayFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }
}
