<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

class CountryLeavePolicyPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::COUNTRY_LEAVE_POLICIES_MANAGE;
    }
}
