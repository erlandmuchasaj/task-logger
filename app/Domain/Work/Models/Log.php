<?php

declare(strict_types=1);

namespace App\Domain\Work\Models;

use App\Domain\Clients\Models\Project;
use App\Domain\Identity\Models\User;
use App\Domain\MasterData\Models\Technology;
use App\Domain\Shared\LogsBusinessActivity;
use App\Domain\Shared\Money;
use App\Domain\Work\Enums\LogType;
use Database\Factories\LogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Time worked (a "log"). Exactly one of: a task log, or a direct project log (BR-02). Needs no
 * approval (BR-01). `rate_minor` and `rate_currency` are the client's rate on the log date, copied
 * when the log is created and never changed by later rate changes (BR-06).
 */
#[Fillable([
    'user_id', 'date', 'project_id', 'task_id', 'technology_id', 'duration_minutes', 'log_type',
    'description', 'rate_minor', 'rate_currency',
])]
#[UseFactory(LogFactory::class)]
final class Log extends Model
{
    /** @use HasFactory<LogFactory> */
    use HasFactory;

    use LogsBusinessActivity;
    use SoftDeletes;

    public function isDirect(): bool
    {
        return $this->task_id === null;
    }

    public function hours(): int
    {
        return intdiv($this->duration_minutes, 60);
    }

    public function minutes(): int
    {
        return $this->duration_minutes % 60;
    }

    /** "2:30" */
    public function duration(): string
    {
        return $this->hours().':'.mb_str_pad((string) $this->minutes(), 2, '0', STR_PAD_LEFT);
    }

    public function rate(): Money
    {
        return Money::of($this->rate_minor, $this->rate_currency);
    }

    /** Value of this log at its snapshot rate, in minor units, rounded half up (hours x hourly rate). */
    public function amount(): Money
    {
        return Money::of(intdiv($this->rate_minor * $this->duration_minutes + 30, 60), $this->rate_currency);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /** @return BelongsTo<Technology, $this> */
    public function technology(): BelongsTo
    {
        return $this->belongsTo(Technology::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'duration_minutes' => 'integer',
            'log_type' => LogType::class,
            'rate_minor' => 'integer',
        ];
    }
}
