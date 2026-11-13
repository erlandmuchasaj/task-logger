<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

class DepartmentPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::DEPARTMENTS_MANAGE;
    }

    protected function createPermission(): ?string
    {
        return Permissions::DEPARTMENTS_CREATE;
    }
}
