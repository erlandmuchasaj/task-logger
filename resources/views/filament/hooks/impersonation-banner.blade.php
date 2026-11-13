@if (app(\Lab404\Impersonate\Services\ImpersonateManager::class)->isImpersonating())
    <div class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-200" role="alert">
        <span>You are acting as <strong>{{ auth()->user()?->getFilamentName() }}</strong>. Everything you change is recorded under their name with you as the impersonator.</span>
        <form method="POST" action="{{ route('impersonation.leave') }}">
            @csrf
            <x-filament::button type="submit" color="danger" size="sm">Stop impersonating</x-filament::button>
        </form>
    </div>
@endif
