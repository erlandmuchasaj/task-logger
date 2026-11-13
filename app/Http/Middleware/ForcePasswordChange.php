<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Filament\Pages\Auth\ChangePassword;
use Closure;
use Illuminate\Http\Request;
use Lab404\Impersonate\Services\ImpersonateManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-53: while the account holds a temporary password, every panel page redirects to ChangePassword.
 * It runs before EnforceTwoFactorGracePeriod, so the password comes first and 2FA second. The page
 * itself and the auth routes (logout) stay reachable. The middleware is persistent, so a Livewire update
 * is checked against the page its component was rendered on. Not applied while an
 * administrator impersonates the person (D-47): they cannot change someone else's password.
 */
final readonly class ForcePasswordChange
{
    public function __construct(private ImpersonateManager $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $this->isExempt($request) && ! $this->impersonation->isImpersonating()) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect(ChangePassword::getUrl());
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs(ChangePassword::getRouteName())
            || $request->routeIs('filament.app.auth.logout');
    }
}
