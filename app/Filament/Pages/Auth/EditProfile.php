<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Domain\Identity\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Lab404\Impersonate\Services\ImpersonateManager;
use SensitiveParameter;

/**
 * A user manages their password and two-factor authentication here. Name and email are HR data
 * (D-14), changed only by the superadmin, so they are shown but not editable.
 */
final class EditProfile extends BaseEditProfile
{
    /** D-47: while someone acts as this user they cannot change the user's password or two-factor setup. */
    public static function canAccess(): bool
    {
        return ! resolve(ImpersonateManager::class)->isImpersonating();
    }

    /**
     * D-53: setting a password here also clears the forced-change flag.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

        if (array_key_exists('password', $data) && $record instanceof User && $record->must_change_password) {
            $record->forceFill(['must_change_password' => false])->saveQuietly();
        }

        return $record;
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
