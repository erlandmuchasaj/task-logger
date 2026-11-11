<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Identity\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;

/**
 * W-01 step 5 [ASSUMED]: a new user gets a set-password link by email. It is a password-reset token,
 * valid for config('auth.passwords.users.expire') minutes (72 hours).
 */
class SendSetPasswordLink
{
    public function handle(User $user): void
    {
        $token = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);

        $notification = new ResetPassword($token);
        $notification->url = Filament::getResetPasswordUrl($token, $user);

        $user->notify($notification);
    }
}
