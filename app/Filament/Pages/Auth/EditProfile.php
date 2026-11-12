<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * A user manages their password and two-factor authentication here. Name and email are HR data
 * (D-14), changed only by the superadmin, so they are shown but not editable.
 */
class EditProfile extends BaseEditProfile
{
    /** D-47: while someone acts as this user they cannot change the user's password or two-factor setup. */
    public static function canAccess(): bool
    {
        return ! app(ImpersonateManager::class)->isImpersonating();
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->disabled()->dehydrated(false);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->disabled()->dehydrated(false);
    }
}
