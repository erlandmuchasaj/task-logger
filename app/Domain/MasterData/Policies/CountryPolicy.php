<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

final class CountryPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::COUNTRIES_MANAGE;
    }

    protected function createPermission(): string
    {
        return Permissions::COUNTRIES_CREATE;
    }
}
