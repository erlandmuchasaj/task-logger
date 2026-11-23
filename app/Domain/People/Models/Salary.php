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
 * BR-29, D-83: salary history is a timeline and a row is never deleted. Its status moves by itself
 * (pending -> active -> superseded). Its amount, currency, date and note change only through
 * applyCorrection(), which the CorrectSalary action calls after authorising the actor.
 */
#[Fillable(['user_id', 'created_by', 'amount_minor', 'currency_code', 'effective_date', 'status', 'description'])]
#[UseFactory(SalaryFactory::class)]
final class Salary extends Model
{
    /** @use HasFactory<SalaryFactory> */
    use HasFactory;

    use LogsBusinessActivity;

    /** The fields a correction may change (D-83). */
    private const array CORRECTABLE = ['amount_minor', 'currency_code', 'effective_date', 'description'];

    private bool $correcting = false;

    public function money(): Money
    {
        return Money::of($this->amount_minor, $this->currency_code);
    }

    /**
     * D-83: fixes a mistaken amount, currency, date or note. Model events still run, so the audit trail
     * keeps the old and new values. A plain update() of these fields still throws.
     *
     * @param  array{amount_minor?: int, currency_code?: string, effective_date?: string, description?: ?string}  $attributes
     */
    public function applyCorrection(array $attributes): void
    {
        $this->correcting = true;

        try {
            $this->update(array_intersect_key($attributes, array_flip(self::CORRECTABLE)));
        } finally {
            $this->correcting = false;
        }
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
            $allowed = $salary->correcting ? [...self::CORRECTABLE, 'status', 'updated_at'] : ['status', 'updated_at'];
            $changedBeyondAllowed = array_diff(array_keys($salary->getDirty()), $allowed);

            throw_if($changedBeyondAllowed !== [], LogicException::class, 'Salary history is a timeline (BR-29): only the status may change, or a correction (D-83) of the amount, currency, date or note.');
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
