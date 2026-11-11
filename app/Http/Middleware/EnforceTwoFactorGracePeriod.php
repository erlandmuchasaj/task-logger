<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\TwoFactorPolicy;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once 2FA is required and a user's grace period is over, every panel page redirects to the profile
 * page, where they enrol. The profile, MFA and logout routes stay reachable, as do Livewire updates
 * (the profile page needs them; no other page can be loaded to produce one).
 */
class EnforceTwoFactorGracePeriod
{
    public function __construct(private readonly TwoFactorPolicy $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->twoFactor->mustSetUpNow($user) && ! $this->isExempt($request)) {
            Notification::make()
                ->title('Set up two-factor authentication to continue')
                ->body('Your grace period has ended. Enable an authenticator app below.')
                ->warning()
                ->persistent()
                ->send();

            return redirect()->route('filament.app.auth.profile');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('filament.app.auth.*') || $request->routeIs('livewire.*');
    }
}
