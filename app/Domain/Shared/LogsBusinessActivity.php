<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * BR-32: every create, update and delete on a business model is written to the activity log
 * with causer, subject and before/after values.
 */
trait LogsBusinessActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName(static::class);
    }
}
