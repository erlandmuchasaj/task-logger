<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Identity\Models\User;
use App\Domain\People\Enums\SalaryStatus;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use Carbon\CarbonInterface;
use Database\Factories\SalaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * BR-29: salary history is append-only. A row's amount, currency, date and note never change and a
 * row is never deleted; only its status moves (pending -> active -> superseded).
 */
#[Fillable(['user_id', 'created_by', 'amount_minor', 'currency_code', 'effective_date', 'status', 'description'])]
#[UseFactory(SalaryFactory::class)]
final class Salary extends Model
{
    /** @use HasFactory<SalaryFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    public function money(): Money
    {
        return Money::of($this->amount_minor, $this->currency_code);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        self::updating(function (self $salary): void {
            $changedBeyondStatus = array_diff(array_keys($salary->getDirty()), ['status', 'updated_at']);

            throw_if($changedBeyondStatus !== [], LogicException::class, 'Salary history is append-only (BR-29): only the status may change.');
        });

        self::deleting(function (): never {
            throw new LogicException('Salary history is append-only (BR-29): rows cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'effective_date' => 'date',
            'status' => SalaryStatus::class,
        ];
    }

    /**
     * The current salary: the latest active row with effective_date on or before the date.
     *
     * @param  Builder<Salary>  $query
     * @return Builder<Salary>
     */
    #[Scope]
    protected function currentOn(Builder $query, int $userId, CarbonInterface $date): Builder
    {
        return $query->where('user_id', $userId)
            ->where('status', SalaryStatus::Active)
            ->whereDate('effective_date', '<=', $date->toDateString())
            ->latest('effective_date')
            ->orderByDesc('id')
            ->limit(1);
    }
}
