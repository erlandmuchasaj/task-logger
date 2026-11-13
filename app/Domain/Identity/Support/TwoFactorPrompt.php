<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use Filament\Actions\Action;

/** The "Enable 2FA now" button shared by the sign-in reminder and the enforcement redirect. */
final class TwoFactorPrompt
{
    public static function enableAction(): Action
    {
        return Action::make('enableTwoFactor')
            ->label('Enable 2FA now')
            ->button()
            ->color('primary')
            ->url(route('filament.app.auth.profile'));
    }
}
