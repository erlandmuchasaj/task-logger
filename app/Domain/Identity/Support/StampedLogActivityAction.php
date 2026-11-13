<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Stamps every activity with the request context before it is saved or buffered (D-38). Values already
 * set on an entry are kept, so code can still record something explicitly.
 */
class StampedLogActivityAction extends LogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        parent::beforeActivityLogged($activity);

        if (! $activity instanceof ActivityLog) {
            return; // another activity model is configured: it has no context columns
        }

        $context = app(ActivityContext::class);

        $activity->client_ip ??= $context->clientIp();
        $activity->user_agent ??= $context->userAgent();
        $activity->impersonator_id ??= $context->impersonatorId();
    }
}
