<?php

declare(strict_types=1);

namespace App\Domain\Work\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Work\Support\LockedMonths;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A calendar month closed for time logging (D-13, BR-07). The month is stored as its first day. A lock
 * is in force while `unlocked_at` is null; unlocking keeps the row so the history stays.
 */
#[Fillable(['month', 'locked_by', 'locked_at', 'unlocked_by', 'unlocked_at'])]
final class TimesheetLock extends Model
{
    use LogsBusinessActivity;

    public function isLocked(): bool
    {
        return $this->unlocked_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    protected static function booted(): void
    {
        self::saved(fn () => resolve(LockedMonths::class)->flush());
        self::deleted(fn () => resolve(LockedMonths::class)->flush());
    }

    protected function casts(): array
    {
        return ['month' => 'date', 'locked_at' => 'datetime', 'unlocked_at' => 'datetime'];
    }
}
