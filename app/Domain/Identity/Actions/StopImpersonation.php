<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use Lab404\Impersonate\Services\ImpersonateManager;

/** Returns to the real user. The listener audits it. */
class StopImpersonation
{
    public function handle(): bool
    {
        $manager = app(ImpersonateManager::class);

        if (! $manager->isImpersonating()) {
            return false;
        }

        $left = $manager->leave();
        session()->forget('password_hash_'.$manager->getCurrentAuthGuardName());

        return $left;
    }
}
