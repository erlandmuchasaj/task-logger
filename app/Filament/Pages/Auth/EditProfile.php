<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;

/**
 * A user manages their password and two-factor authentication here. Name and email are HR data
 * (D-14), changed only by the superadmin, so they are shown but not editable.
 */
class EditProfile extends BaseEditProfile
{
    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->disabled()->dehydrated(false);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->disabled()->dehydrated(false);
    }
}
