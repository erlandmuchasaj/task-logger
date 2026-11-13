<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Models;

use App\Domain\MasterData\Enums\Proration;
use App\Domain\Shared\LogsBusinessActivity;
use Carbon\CarbonInterface;
use Database\Factories\CountryLeavePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'country_id', 'leave_type_id', 'days_per_year', 'effective_from', 'proration',
    'carry_over_enabled', 'carry_over_max_days', 'carry_over_expires_after_months',
])]
#[UseFactory(CountryLeavePolicyFactory::class)]
final class CountryLeavePolicy extends Model
{
    /** @use HasFactory<CountryLeavePolicyFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:1',
            'effective_from' => 'date',
            'proration' => Proration::class,
            'carry_over_enabled' => 'boolean',
            'carry_over_max_days' => 'decimal:1',
            'carry_over_expires_after_months' => 'integer',
        ];
    }

    /**
     * The active policy for a date is the row with the latest effective_from on or before it (FR-06).
     *
     * @param  Builder<CountryLeavePolicy>  $query
     * @return Builder<CountryLeavePolicy>
     */
    #[Scope]
    protected function activeOn(Builder $query, int $countryId, int $leaveTypeId, CarbonInterface $date): Builder
    {
        return $query->where('country_id', $countryId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->limit(1);
    }
}
