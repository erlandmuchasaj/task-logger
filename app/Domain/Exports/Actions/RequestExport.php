<?php

declare(strict_types=1);

namespace App\Domain\Exports\Actions;

use App\Domain\Exports\Enums\ExportStatus;
use App\Domain\Exports\Enums\ExportType;
use App\Domain\Exports\Jobs\RunExport;
use App\Domain\Exports\Models\Export;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\ActivityContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * BR-33: queues an export. The request context (IP, impersonator) travels with it so the audit trail of
 * the finished export names who really asked (D-38). Permissions are checked here and again in the job.
 *
 * @phpstan-type Filters array<string, mixed>
 */
class RequestExport
{
    /** @param  array<string, mixed>  $filters */
    public function handle(User $actor, ExportType $type, array $filters = []): Export
    {
        if (! $type->exporter()->canExport($actor)) {
            throw new AuthorizationException('You may not export '.$type->value.'.');
        }

        $export = Export::create([
            'user_id' => $actor->getKey(),
            'type' => $type,
            'status' => ExportStatus::Queued,
            'filters' => array_filter($filters, fn (mixed $v): bool => $v !== null && $v !== ''),
            'request_context' => app(ActivityContext::class)->snapshot(),
        ]);

        RunExport::dispatch($export->getKey());

        return $export;
    }
}
