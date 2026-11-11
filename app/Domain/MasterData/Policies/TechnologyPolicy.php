<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

class TechnologyPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::TECHNOLOGIES_MANAGE;
    }

    protected function createPermission(): ?string
    {
        return Permissions::TECHNOLOGIES_CREATE;
    }
}
