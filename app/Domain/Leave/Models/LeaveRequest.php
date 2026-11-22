<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Leave\Enums\ApprovalStage;
use App\Domain\Leave\Enums\DayPart;
use App\Domain\Leave\Enums\LeaveStatus;
use App\Domain\MasterData\Models\LeaveType;
use App\Domain\Shared\LogsBusinessActivity;
use Carbon\CarbonImmutable;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One leave request (W-05) or a direct day off (W-06, created_by is not the employee). `days_requested`
 * is computed on the server (BR-16); `reviewed_*` hold the final decision (D-07).
 */
#[Fillable([
    'user_id', 'created_by', 'leave_type_id', 'start_date', 'end_date', 'start_day_part', 'end_day_part',
    'days_requested', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'reminder_sent_at', 'escalated_at',
])]
#[UseFactory(LeaveRequestFactory::class)]
final class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    use LogsBusinessActivity;
    use SoftDeletes;

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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /** @return HasMany<LeaveRequestApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveRequestApproval::class);
    }

    /** @return HasMany<LeaveLedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /** Created by the employee for themselves (W-05), as opposed to a direct day off (W-06). */
    public function isOwnRequest(): bool
    {
        return $this->created_by === $this->user_id;
    }

    /** D-70: a person without a manager (the superadmin) approved their own request when they made it. */
    public function wasSelfApproved(): bool
    {
        return $this->status === LeaveStatus::Approved && $this->reviewed_by !== null && $this->reviewed_by === $this->user_id;
    }

    /** D-71 / BR-19: "taken" means today has reached the first day, in the employee's own calendar. */
    public function hasStarted(CarbonImmutable $today): bool
    {
        return $this->start_date->toDateString() <= $today->toDateString();
    }

    /** D-72: still pending after its first day (on the first day it is escalated, not yet overdue). A computed state, not a status: HR resolves it by deciding or cancelling. */
    public function isOverdue(CarbonImmutable $today): bool
    {
        return $this->status === LeaveStatus::Pending && $this->start_date->toDateString() < $today->toDateString();
    }

    public function stageDecision(ApprovalStage $stage): ?LeaveRequestApproval
    {
        return $this->approvals->firstWhere('stage', $stage);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    #[Scope]
    protected function overdue(Builder $query, CarbonImmutable $today): Builder
    {
        return $query->where('status', LeaveStatus::Pending)->whereDate('start_date', '<', $today->toDateString());
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'start_day_part' => DayPart::class,
            'end_day_part' => DayPart::class,
            'days_requested' => 'decimal:1',
            'status' => LeaveStatus::class,
            'reviewed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }
}
