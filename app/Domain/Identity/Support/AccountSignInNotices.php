<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;

/** Listeners for sign-in and password-reset events (D-53, D-26). */
class AccountSignInNotices
{
    public function __construct(private readonly TwoFactorPolicy $twoFactor) {}

    /** A password chosen through the emailed reset link is the person's own: no forced change afterwards. */
    public function passwordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User && $event->user->must_change_password) {
            $event->user->forceFill(['must_change_password' => false])->saveQuietly();
        }
    }

    /** While 2FA is required and the grace period runs, greet the person with a prominent reminder on sign-in. */
    public function login(Login $event): void
    {
        // The password comes first (D-53): a temporary-password user sees the 2FA banner once it is replaced.
        if (! $event->user instanceof User || $event->user->must_change_password || ! $this->twoFactor->isInGracePeriod($event->user)) {
            return;
        }

        $deadline = $this->twoFactor->graceEndsAt($event->user);

        Notification::make()
            ->title('Two-factor authentication is required')
            ->body('Set up an authenticator app before '.$deadline?->toFormattedDayDateString().'. After that date you cannot use the app until you do.')
            ->icon('heroicon-o-shield-exclamation')
            ->warning()
            ->persistent()
            ->actions([TwoFactorPrompt::enableAction()])
            ->send();
    }
}
