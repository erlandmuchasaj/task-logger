<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

final class BankHolidayPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::BANK_HOLIDAYS_MANAGE;
    }

    protected function viewPermission(): string
    {
        return Permissions::BANK_HOLIDAYS_VIEW;
    }
}
