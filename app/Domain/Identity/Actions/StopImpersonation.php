<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use Illuminate\Contracts\Auth\Factory;
use Lab404\Impersonate\Services\ImpersonateManager;

/** Returns to the real user. The listener audits it. */
final class StopImpersonation
{
    public function handle(): bool
    {
        $manager = resolve(ImpersonateManager::class);

        if (! $manager->isImpersonating()) {
            return false;
        }

        $left = $manager->leave();
        session()->forget('password_hash_'.resolve(Factory::class)->getDefaultDriver());

        return $left;
    }
}
