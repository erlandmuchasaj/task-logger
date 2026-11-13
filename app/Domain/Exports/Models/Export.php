<?php

declare(strict_types=1);

namespace App\Domain\Exports\Models;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested export and its file. A tool record, not a business record: its own status changes are not
 * audited; the completion is (event `exported` on the export).
 *
 * @property array{client_ip?: ?string, user_agent?: ?string, impersonator_id?: ?int}|null $request_context
 */
#[Fillable(['user_id', 'type', 'status', 'filters', 'request_context', 'file_path', 'rows', 'error', 'completed_at', 'expires_at'])]
final class Export extends Model
{
    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Completed
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'request_context' => 'array',
            'rows' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
