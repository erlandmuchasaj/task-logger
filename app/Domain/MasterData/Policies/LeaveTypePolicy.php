<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

class LeaveTypePolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::LEAVE_TYPES_MANAGE;
    }
}
