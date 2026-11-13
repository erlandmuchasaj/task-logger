<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\TwoFactorPolicy;
use App\Domain\Identity\Support\TwoFactorPrompt;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once 2FA is required and a user's grace period is over, every panel page redirects to the profile
 * page, where they enrol. Only the profile, MFA and logout routes stay reachable. The middleware is
 * persistent, so a Livewire update is checked against the page its component was rendered on: an
 * open page cannot keep invoking actions once the lock starts, while the profile page's own updates
 * (enrolment) still pass.
 */
final readonly class EnforceTwoFactorGracePeriod
{
    public function __construct(private TwoFactorPolicy $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->twoFactor->mustSetUpNow($user) && ! $this->isExempt($request)) {
            Notification::make()
                ->title('Set up two-factor authentication to continue')
                ->body('Your grace period has ended. Enable an authenticator app in the "Multi-factor authentication" section below; every other page stays locked until you do.')
                ->icon('heroicon-o-shield-exclamation')
                ->danger()
                ->persistent()
                ->actions([TwoFactorPrompt::enableAction()])
                ->send();

            return to_route('filament.app.auth.profile');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('filament.app.auth.*');
    }
}
