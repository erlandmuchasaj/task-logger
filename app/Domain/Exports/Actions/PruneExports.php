<?php

declare(strict_types=1);

namespace App\Domain\Exports\Actions;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use Illuminate\Support\Facades\Storage;

/** Daily: deletes export files past their expiry and marks the rows expired (idempotent). */
final class PruneExports
{
    /** @return int number of exports expired */
    public function handle(): int
    {
        $disk = Storage::disk((string) config('task-logger.exports.disk'));
        $count = 0;

        Export::query()->where('status', ExportStatus::Completed)->where('expires_at', '<', now())->each(function (Export $export) use ($disk, &$count): void {
            if ($export->file_path !== null) {
                $disk->delete($export->file_path);
            }

            $export->update(['status' => ExportStatus::Expired, 'file_path' => null]);
            $count++;
        });

        return $count;
    }
}
