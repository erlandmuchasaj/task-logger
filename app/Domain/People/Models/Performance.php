<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\LogsBusinessActivity;
use Database\Factories\PerformanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A performance review (W-09). Read access is restricted (BR-27, D-21); rows are kept after anonymisation (BR-28). */
#[Fillable(['user_id', 'created_by', 'score', 'notes', 'review_date'])]
#[UseFactory(PerformanceFactory::class)]
class Performance extends Model
{
    /** @use HasFactory<PerformanceFactory> */
    use HasFactory, LogsBusinessActivity;

    protected function casts(): array
    {
        return ['score' => 'integer', 'review_date' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
