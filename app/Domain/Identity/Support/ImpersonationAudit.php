<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

/**
 * D-47: starting and stopping an impersonation are audited with the real person as the causer and the user
 * acted as the subject, so the trail shows who looked at whose account and when.
 */
final class ImpersonationAudit
{
    public function taken(TakeImpersonation $event): void
    {
        $this->write('impersonation_started', $event->impersonator, $event->impersonated);
    }

    public function left(LeaveImpersonation $event): void
    {
        $this->write('impersonation_ended', $event->impersonator, $event->impersonated);
    }

    private function write(string $event, mixed $impersonator, mixed $impersonated): void
    {
        if ($impersonator === null || $impersonated === null) {
            return;
        }

        activity()->causedBy($impersonator)->performedOn($impersonated)->event($event)->log($event);
    }
}
