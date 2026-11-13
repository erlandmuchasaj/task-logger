<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * [OPTIONAL] D-47: the superadmin acts as another user to see what they see. Needs employees.impersonate. Not
 * yourself, not while already impersonating, and only an active account that is not root (User::canBeImpersonated).
 * The start is audited by the TakeImpersonation listener; every audited write afterwards carries `impersonator_id` (D-38).
 */
final class StartImpersonation
{
    public function handle(User $actor, User $target): void
    {
        Gate::forUser($actor)->authorize('impersonate', $target);

        $manager = resolve(ImpersonateManager::class);

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['employee' => 'You cannot impersonate yourself.']);
        }

        if ($manager->isImpersonating()) {
            throw ValidationException::withMessages(['employee' => 'Stop the current impersonation first.']);
        }

        if (! $target->canBeImpersonated()) {
            throw ValidationException::withMessages(['employee' => 'This account cannot be impersonated.']);
        }

        if (! $manager->take($actor, $target)) {
            throw ValidationException::withMessages(['employee' => 'The impersonation could not be started.']);
        }

        // Filament's session guard compares a stored password hash with the signed-in user: let it store the new user's.
        session()->forget('password_hash_'.resolve(Factory::class)->getDefaultDriver());
    }
}
