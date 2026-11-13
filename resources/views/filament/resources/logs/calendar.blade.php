@php
    $calendar = $this->calendar();
    $visibleEntries = 3;
@endphp

<x-filament::section>
    <div class="mb-4 flex items-start justify-between gap-2">
        <x-filament::button color="gray" size="sm" class="shrink-0" wire:click="previousCalendarMonth" aria-label="Previous month">&larr;</x-filament::button>
        <div class="flex min-w-0 flex-1 flex-wrap items-center justify-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-semibold">{{ $calendar['label'] }}</h2>
            <x-filament::button color="gray" size="sm" outlined wire:click="currentCalendarMonth">Today</x-filament::button>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Month total: <span class="font-medium text-gray-950 dark:text-white">{{ $calendar['total'] }}</span>
            </span>
        </div>
        <x-filament::button color="gray" size="sm" class="shrink-0" wire:click="nextCalendarMonth" aria-label="Next month">&rarr;</x-filament::button>
    </div>

    @if ($calendar['blocked'])
        <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
            This month is locked, so time can't be logged or changed here.
        </div>
    @elseif ($calendar['locked'])
        <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30">
            This month is locked. You can still log and edit time in it because you may edit locked months.
        </div>
    @endif

    <div class="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 text-xs dark:border-white/10 dark:bg-white/10">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)
            <div class="bg-gray-50 p-2 text-center font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300">{{ $name }}</div>
        @endforeach

        @foreach ($calendar['weeks'] as $weekIndex => $week)
            @foreach ($week as $dayIndex => $day)
                @if ($day === null)
                    <div class="min-h-20 bg-gray-50 opacity-40 sm:min-h-28 dark:bg-gray-900" wire:key="calendar-pad-{{ $weekIndex }}-{{ $dayIndex }}"></div>
                @else
                    <div
                        wire:key="calendar-day-{{ $day['date'] }}"
                        @if ($day['isMissing']) title="No time logged" @endif
                        class="relative min-h-20 p-1.5 sm:min-h-28
                            {{ $day['isMissing'] ? 'bg-red-50 dark:bg-red-500/10' : ($day['isWorkingDay'] ? 'bg-white dark:bg-gray-950' : 'bg-gray-50 dark:bg-gray-900') }}
                            {{ $day['isToday'] ? 'ring-2 ring-inset ring-primary-500' : '' }}
                            {{ $day['canLog'] ? '' : 'opacity-60' }}"
                    >
                        @if ($day['canLog'])
                            <button
                                type="button"
                                class="absolute inset-0 h-full w-full cursor-pointer hover:bg-gray-950/5 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:bg-white/5"
                                wire:click="mountAction('logTime', { date: '{{ $day['date'] }}' })"
                                aria-label="Log time on {{ $day['label'] }}"
                            ></button>
                        @endif

                        {{-- The content sits above the day button but lets clicks through to it; only links and the toggle take clicks. --}}
                        <div class="pointer-events-none relative flex flex-col gap-0.5" x-data="{ open: false }">
                            <div class="flex items-start justify-between gap-1">
                                <span class="{{ $day['isToday'] ? 'font-bold text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $day['day'] }}</span>
                                @if ($day['totalMinutes'] > 0)
                                    <span class="font-semibold text-gray-950 dark:text-white">{{ $day['total'] }}</span>
                                @endif
                            </div>

                            @foreach ($day['holidays'] as $holiday)
                                <div class="truncate rounded bg-amber-100 px-1 py-0.5 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200" title="Bank holiday: {{ $holiday }}">{{ $holiday }}</div>
                            @endforeach

                            @foreach ($day['leave'] as $leave)
                                <div class="truncate rounded bg-sky-100 px-1 py-0.5 text-sky-900 dark:bg-sky-500/20 dark:text-sky-200" title="{{ $leave }}">{{ $leave }}</div>
                            @endforeach

                            @foreach ($day['entries'] as $entryIndex => $entry)
                                <a
                                    @if ($entry['canEdit']) href="{{ \App\Filament\Resources\Logs\LogResource::getUrl('edit', ['record' => $entry['id']]) }}" @endif
                                    @if ($entryIndex >= $visibleEntries) x-show="open" x-cloak @endif
                                    wire:key="calendar-entry-{{ $entry['id'] }}"
                                    title="{{ $entry['label'] }} · {{ $entry['duration'] }}"
                                    class="hidden items-start justify-between gap-1 rounded bg-gray-100 px-1 py-0.5 text-gray-800 sm:flex dark:bg-white/10 dark:text-gray-200
                                        {{ $entry['canEdit'] ? 'pointer-events-auto relative z-10 hover:bg-gray-200 dark:hover:bg-white/20' : '' }}"
                                >
                                    <span class="line-clamp-2 min-w-0 break-words">{{ $entry['label'] }}</span>
                                    <span class="shrink-0 font-medium">{{ $entry['duration'] }}</span>
                                </a>
                            @endforeach

                            @if (count($day['entries']) > $visibleEntries)
                                <button
                                    type="button"
                                    class="pointer-events-auto relative z-10 hidden text-left text-primary-600 hover:underline sm:block dark:text-primary-400"
                                    x-on:click="open = ! open"
                                >
                                    <span x-show="! open">+{{ count($day['entries']) - $visibleEntries }} more</span>
                                    <span x-show="open" x-cloak>Show less</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>

    <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-amber-100 dark:bg-amber-500/20"></span> Bank holiday</span>
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-sky-100 dark:bg-sky-500/20"></span> Leave</span>
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-red-50 ring-1 ring-red-200 dark:bg-red-500/10 dark:ring-red-500/30"></span> No time logged on a working day</span>
        <span>Click a day to log time.</span>
    </p>
</x-filament::section>

{{-- A page with a table has its action modals rendered by the table. The table is hidden on this tab, and the log modal opens from here. --}}
<x-filament-actions::modals />
