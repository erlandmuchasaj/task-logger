<x-filament-panels::page>
    <x-filament::section heading="Balance" description="Available is your balance minus the requests still waiting for a decision.">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($this->balances() as $row)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" wire:key="balance-{{ $row['type'] }}">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $row['type'] }}</div>
                    <div class="mt-1 text-3xl font-semibold">{{ $row['available'] }} <span class="text-base font-normal text-gray-500">days available</span></div>
                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Balance {{ $row['balance'] }} · pending {{ $row['pending'] }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No leave type affects a balance yet.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->upcoming() }} open request(s)</span>
            <div class="flex flex-wrap items-center gap-2">
                @can(\App\Domain\Identity\Permissions::BANK_HOLIDAYS_VIEW)
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\LeaveRequests\LeaveRequestResource::getUrl('index', ['tab' => 'calendar'])">Leave calendar</x-filament::button>
                @endcan
                <x-filament::button tag="a" :href="\App\Filament\Resources\LeaveRequests\LeaveRequestResource::getUrl()">Leave requests</x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
