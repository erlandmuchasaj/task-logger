<?php

declare(strict_types=1);

namespace App\Domain\People\Support;

use App\Domain\Identity\Models\User;

/** [DERIVED] A user's manager chain must never loop: nobody manages themselves or their own manager. */
final class ManagerChain
{
    public static function wouldCycle(?int $userId, ?int $managerId): bool
    {
        if ($managerId === null) {
            return false;
        }

        if ($userId !== null && $managerId === $userId) {
            return true;
        }

        $seen = [];
        $current = $managerId;

        while ($current !== null && ! isset($seen[$current])) {
            if ($userId !== null && $current === $userId) {
                return true;
            }

            $seen[$current] = true;
            $current = User::query()->withTrashed()->whereKey($current)->value('manager_id');
        }

        return false;
    }
}
