<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\LedgerEntryType;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\Shared\LogsBusinessActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (SPEC 5.5): a balance is the sum of `days`. Nothing updates or deletes an entry; a
 * cancellation writes a reversal.
 */
#[Fillable([
    'user_id', 'leave_type_id', 'entry_type', 'days', 'effective_on', 'expires_on', 'leave_request_id', 'note', 'created_by',
])]
class LeaveLedgerEntry extends Model
{
    use LogsBusinessActivity;

    protected function casts(): array
    {
        return [
            'entry_type' => LedgerEntryType::class,
            'days' => 'decimal:1',
            'effective_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
