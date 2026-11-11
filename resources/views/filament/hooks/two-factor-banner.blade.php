@php
    $twoFactor = app(\App\Domain\Identity\Support\TwoFactorPolicy::class);
    $user = auth()->user();
    $deadline = $user && $twoFactor->isInGracePeriod($user) ? $twoFactor->graceEndsAt($user) : null;
@endphp

@if ($deadline)
    <div class="mb-4 rounded-lg bg-warning-50 p-4 text-sm text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
        Two-factor authentication is required. Enable it on your
        <a href="{{ route('filament.app.auth.profile') }}" class="font-semibold underline">profile</a>
        by {{ $deadline->toFormattedDayDateString() }}.
    </div>
@endif
