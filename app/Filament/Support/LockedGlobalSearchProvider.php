<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\TwoFactorPolicy;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

/**
 * Global search is a Livewire component on every panel page, including the profile page a locked-out
 * user is sent to (D-26, D-53). It would otherwise keep returning records from there, so while the
 * person must still enrol in 2FA or replace a temporary password it finds nothing.
 */
final readonly class LockedGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(
        private TwoFactorPolicy $twoFactor,
        private DefaultGlobalSearchProvider $default,
    ) {}

    public function getResults(string $query): ?GlobalSearchResults
    {
        $user = auth()->user();

        if ($user instanceof User && ($user->must_change_password || $this->twoFactor->mustSetUpNow($user))) {
            return GlobalSearchResults::make();
        }

        return $this->default->getResults($query);
    }
}
