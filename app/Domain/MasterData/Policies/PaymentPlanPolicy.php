<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Policies;

use App\Domain\Identity\Permissions;

final class PaymentPlanPolicy extends MasterDataPolicy
{
    protected function managePermission(): string
    {
        return Permissions::PAYMENT_PLANS_MANAGE;
    }
}
