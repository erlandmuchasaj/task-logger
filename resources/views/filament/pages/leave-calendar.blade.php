<x-filament-panels::page>
    <x-filament::section>
        <div class="mb-4 flex items-center justify-between gap-2">
            <x-filament::button color="gray" size="sm" wire:click="previousMonth">&larr;</x-filament::button>
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold">{{ $this->first()->format('F Y') }}</h2>
                <x-filament::button color="gray" size="sm" outlined wire:click="today">Today</x-filament::button>
            </div>
            <x-filament::button color="gray" size="sm" wire:click="nextMonth">&rarr;</x-filament::button>
        </div>

        <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 text-xs dark:border-white/10 dark:bg-white/10">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)
                <div class="bg-gray-50 p-2 text-center font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300">{{ $name }}</div>
            @endforeach

            @foreach ($this->weeks() as $week)
                @foreach ($week as $day)
                    <div class="min-h-24 bg-white p-1.5 dark:bg-gray-950 {{ $day['inMonth'] ? '' : 'opacity-40' }}" wire:key="day-{{ $day['date']->toDateString() }}">
                        <div class="mb-1 text-right text-gray-500">{{ $day['date']->day }}</div>
                        @foreach ($day['holidays'] as $holiday)
                            <div class="mb-0.5 truncate rounded bg-amber-100 px-1 py-0.5 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200" title="{{ $holiday }}">{{ $holiday }}</div>
                        @endforeach
                        @foreach ($day['leave'] as $entry)
                            <div class="mb-0.5 truncate rounded px-1 py-0.5 {{ $entry['pending'] ? 'border border-dashed border-sky-400 text-sky-800 dark:text-sky-200' : 'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-200' }}" title="{{ $entry['label'] }}{{ $entry['pending'] ? ' (pending)' : '' }}">{{ $entry['label'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>

        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Amber: bank holidays. Blue: leave; dashed means still waiting for approval.</p>
    </x-filament::section>
</x-filament-panels::page>
