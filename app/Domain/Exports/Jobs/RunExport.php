<?php

declare(strict_types=1);

namespace App\Domain\Exports\Jobs;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Models\Export;
use App\Domain\Exports\Support\CsvSafe;
use App\Domain\Identity\Support\ActivityContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;
use Throwable;

/**
 * Builds the CSV for an export (BR-33). It re-checks the requester's permissions, uses the columns that
 * person is allowed to see, streams in chunks so memory stays flat, and writes to the private disk.
 * Idempotent: only a queued export is processed.
 */
#[Timeout(600)]
#[Tries(1)]
final class RunExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    public function __construct(public readonly int $exportId) {}

    public function handle(): void
    {
        $export = Export::query()->with('user')->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }

        $export->update(['status' => ExportStatus::Processing]);

        try {
            $actor = $export->user;
            $exporter = $export->type->exporter();

            throw_if($actor->isBanned() || ! $exporter->canExport($actor), RuntimeException::class, 'The requester is no longer allowed to export this.');

            $columns = $exporter->columns($actor);
            $disk = Storage::disk((string) config('task-logger.exports.disk'));
            $path = 'exports/'.$export->getKey().'-'.Str::random(24).'.csv';

            $stream = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+');
            throw_if($stream === false, RuntimeException::class, 'Could not open a temporary file.');

            fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel reads names correctly
            fputcsv($stream, array_values($columns), ',', '"', '');

            $rows = 0;
            $exporter->query($actor, $export->filters ?? [])->orderBy('id')->chunkById(500, function ($chunk) use ($stream, $exporter, $actor, $columns, &$rows): void {
                /** @var Model $record */
                foreach ($chunk as $record) {
                    $values = $exporter->row($record, $actor);
                    fputcsv($stream, array_map(fn (string $key): string => CsvSafe::cell($values[$key] ?? null), array_keys($columns)), ',', '"', '');
                    $rows++;
                }
            });

            rewind($stream);
            $disk->writeStream($path, $stream);
            fclose($stream);

            $export->update([
                'status' => ExportStatus::Completed,
                'file_path' => $path,
                'rows' => $rows,
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('task-logger.exports.keep_days')),
            ]);

            // Audit (BR-32, D-38): the requester is the causer, with the IP and impersonator of the original request.
            resolve(ActivityContext::class)->using($export->request_context ?? [], fn () => resolve(CauserResolver::class)->withCauser($actor, function () use ($export, $rows, $columns): void {
                activity()->performedOn($export)->event('exported')
                    ->withProperties(['type' => $export->type->value, 'rows' => $rows, 'columns' => array_keys($columns), 'filters' => $export->filters])
                    ->log('exported');
            }));
        } catch (Throwable $throwable) {
            report($throwable);
            $export->update(['status' => ExportStatus::Failed, 'error' => Str::limit($throwable->getMessage(), 500)]);
        }
    }
}
