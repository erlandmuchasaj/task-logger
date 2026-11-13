@php
    $twoFactor = app(\App\Domain\Identity\Support\TwoFactorPolicy::class);
    $user = auth()->user();
    // D-53: the password comes first; the 2FA prompt waits until the temporary password is replaced.
    $deadline = $user && ! $user->must_change_password && $twoFactor->isInGracePeriod($user) ? $twoFactor->graceEndsAt($user) : null;
    $onProfile = request()->routeIs('filament.app.auth.profile');
@endphp

@if ($deadline)
    @php
        $daysLeft = max(0, (int) ceil(now()->diffInDays($deadline, false)));
    @endphp
    <x-filament::callout
        color="warning"
        icon="heroicon-o-shield-exclamation"
        heading="Two-factor authentication is required"
        class="mb-6"
        role="alert"
    >
        <x-slot name="description">
            Set up an authenticator app by <strong>{{ $deadline->toFormattedDayDateString() }}</strong>
            ({{ $daysLeft }} {{ \Illuminate\Support\Str::plural('day', $daysLeft) }} left).
            After that date every page is locked until you do.
            @if ($onProfile)
                Use the <strong>Multi-factor authentication</strong> section below.
            @endif
        </x-slot>

        @unless ($onProfile)
            <x-slot name="controls">
                <x-filament::button tag="a" :href="route('filament.app.auth.profile')" color="warning" icon="heroicon-m-shield-check">
                    Enable 2FA now
                </x-filament::button>
            </x-slot>
        @endunless
    </x-filament::callout>
@endif
