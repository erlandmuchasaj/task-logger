@php
    $calendar = $this->calendar();
    $canRequest = $calendar['canRequest'];
    $visibleChips = 3;
    $visibleDots = 6;
    $chipStyle = fn (array $chip): string => ($chip['pending']
        ? 'border border-dashed border-sky-500 text-sky-800 dark:border-sky-400 dark:text-sky-200'
        : 'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-200').($chip['mine'] ? ' font-bold ring-1 ring-sky-600 dark:ring-sky-400' : '');
@endphp

<x-filament::section>
    <div class="mb-4 flex items-start justify-between gap-2">
        <x-filament::button color="gray" size="sm" class="shrink-0" wire:click="previousCalendarMonth" aria-label="Previous month">&larr;</x-filament::button>
        <div class="flex min-w-0 flex-1 flex-wrap items-center justify-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-semibold">{{ $calendar['label'] }}</h2>
            <x-filament::button color="gray" size="sm" outlined wire:click="currentCalendarMonth">Today</x-filament::button>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <x-filament::input.checkbox wire:model.live="onlyMine" />
                <span>Only mine</span>
            </label>
        </div>
        <x-filament::button color="gray" size="sm" class="shrink-0" wire:click="nextCalendarMonth" aria-label="Next month">&rarr;</x-filament::button>
    </div>

    {{--
        Dragging: pressing on a day starts a selection, entering other days stretches it and releasing opens the request
        form for the range (a press and release on one day is a single day). The handlers sit on the day cell, not on its
        button, so a drag that passes over a request chip still reaches the cell. A scroll gesture on a touch screen ends
        in pointercancel and selects nothing.
    --}}
    <div
        class="grid select-none grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 text-xs dark:border-white/10 dark:bg-white/10"
        x-data="{
            anchor: null,
            current: null,
            get from() { return this.anchor === null ? null : (this.anchor < this.current ? this.anchor : this.current) },
            get to() { return this.anchor === null ? null : (this.anchor > this.current ? this.anchor : this.current) },
            isSelected(date) { return this.anchor !== null && date >= this.from && date <= this.to },
            begin(date, event) {
                if (event.button !== 0 || ! event.isPrimary) return
                this.anchor = date
                this.current = date
            },
            over(date, event) {
                if (this.anchor === null) return
                if (event.pointerType === 'mouse' && event.buttons === 0) return this.reset()
                this.current = date
            },
            finish(date) {
                if (this.anchor === null) return
                this.current = date
                const range = { start: this.from, end: this.to }
                this.reset()
                $wire.mountAction('request', range)
            },
            reset() {
                this.anchor = null
                this.current = null
            },
        }"
        x-on:pointerup.window="reset()"
        x-on:pointercancel.window="reset()"
    >
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)
            <div class="bg-gray-50 p-2 text-center font-medium text-gray-600 dark:bg-gray-900 dark:text-gray-300">{{ $name }}</div>
        @endforeach

        @foreach ($calendar['weeks'] as $weekIndex => $week)
            @foreach ($week as $dayIndex => $day)
                @if ($day === null)
                    <div class="min-h-20 bg-gray-50 opacity-40 sm:min-h-28 dark:bg-gray-900" wire:key="leave-pad-{{ $weekIndex }}-{{ $dayIndex }}"></div>
                @else
                    <div
                        wire:key="leave-day-{{ $day['date'] }}"
                        class="relative min-h-20 p-1.5 sm:min-h-28
                            {{ $day['isWorkingDay'] ? 'bg-white dark:bg-gray-950' : 'bg-gray-50 dark:bg-gray-900' }}
                            {{ $day['isToday'] ? 'ring-2 ring-inset ring-primary-500' : '' }}"
                        @if ($canRequest)
                            x-bind:class="{ 'bg-primary-100! dark:bg-primary-500/30!': isSelected('{{ $day['date'] }}') }"
                            x-on:pointerdown="begin('{{ $day['date'] }}', $event)"
                            x-on:pointerenter="over('{{ $day['date'] }}', $event)"
                            x-on:pointerup="finish('{{ $day['date'] }}')"
                        @endif
                    >
                        @if ($canRequest)
                            {{-- A mouse click is the pointer events above; a keyboard click (detail 0) opens the day on its own. --}}
                            <button
                                type="button"
                                class="absolute inset-0 h-full w-full cursor-pointer hover:bg-gray-950/5 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:bg-white/5"
                                x-on:click="$event.detail === 0 && $wire.mountAction('request', { start: '{{ $day['date'] }}', end: '{{ $day['date'] }}' })"
                                aria-label="Request leave on {{ $day['label'] }}"
                            ></button>
                        @endif

                        {{-- The content sits above the day button but lets clicks through to it; only chips that open a request take clicks. --}}
                        <div class="pointer-events-none relative flex flex-col gap-0.5" x-data="{ open: false }">
                            <div class="flex items-start justify-between gap-1">
                                <span class="{{ $day['isToday'] ? 'font-bold text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400' }}">{{ $day['day'] }}</span>
                            </div>

                            @foreach ($day['holidays'] as $holiday)
                                <div class="hidden truncate rounded bg-amber-100 px-1 py-0.5 text-amber-900 sm:block dark:bg-amber-500/20 dark:text-amber-200" title="Bank holiday: {{ $holiday }}">{{ $holiday }}</div>
                            @endforeach

                            @foreach ($day['leave'] as $chipIndex => $chip)
                                @if ($chip['id'] !== null)
                                    <button
                                        type="button"
                                        wire:key="leave-chip-{{ $day['date'] }}-{{ $chip['id'] }}"
                                        wire:click="mountAction('leaveDetails', { id: {{ $chip['id'] }} })"
                                        x-on:pointerdown.stop
                                        @if ($chipIndex >= $visibleChips) x-show="open" x-cloak @endif
                                        title="{{ $chip['title'] }}"
                                        class="pointer-events-auto relative z-10 hidden cursor-pointer truncate rounded px-1 py-0.5 text-left hover:brightness-95 sm:block {{ $chipStyle($chip) }}"
                                    >{{ $chip['label'] }}</button>
                                @else
                                    <div
                                        @if ($chipIndex >= $visibleChips) x-show="open" x-cloak @endif
                                        title="{{ $chip['title'] }}"
                                        class="hidden truncate rounded px-1 py-0.5 sm:block {{ $chipStyle($chip) }}"
                                    >{{ $chip['label'] }}</div>
                                @endif
                            @endforeach

                            @if (count($day['leave']) > $visibleChips)
                                <button
                                    type="button"
                                    class="pointer-events-auto relative z-10 hidden text-left text-primary-600 hover:underline sm:block dark:text-primary-400"
                                    x-on:pointerdown.stop
                                    x-on:click="open = ! open"
                                >
                                    <span x-show="! open">+{{ count($day['leave']) - $visibleChips }} more</span>
                                    <span x-show="open" x-cloak>Show less</span>
                                </button>
                            @endif

                            {{-- A phone has no room for names: one dot per holiday and request, the list under the grid has the names. --}}
                            @if ($day['holidays'] !== [] || $day['leave'] !== [])
                                <div class="flex flex-wrap gap-0.5 sm:hidden">
                                    @foreach ($day['holidays'] as $holiday)
                                        <span class="size-2 rounded-full bg-amber-400" title="{{ $holiday }}"></span>
                                    @endforeach
                                    @foreach (array_slice($day['leave'], 0, $visibleDots) as $chip)
                                        <span
                                            class="size-2 rounded-full {{ $chip['pending'] ? 'border border-dashed border-sky-500' : 'bg-sky-500' }} {{ $chip['mine'] ? 'ring-2 ring-sky-700 dark:ring-sky-300' : '' }}"
                                            title="{{ $chip['title'] }}"
                                        ></span>
                                    @endforeach
                                    @if (count($day['leave']) > $visibleDots)
                                        <span class="text-[10px] leading-2 text-gray-500 dark:text-gray-400">+{{ count($day['leave']) - $visibleDots }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        @endforeach
    </div>

    <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-amber-100 dark:bg-amber-500/20"></span> Bank holiday</span>
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-sky-100 dark:bg-sky-500/20"></span> Approved or taken</span>
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded border border-dashed border-sky-500"></span> Pending approval</span>
        <span class="flex items-center gap-1"><span class="inline-block size-3 rounded bg-sky-100 ring-1 ring-sky-600 dark:bg-sky-500/20 dark:ring-sky-400"></span> Yours</span>
        @if ($canRequest)
            <span>Click a day, or drag across days, to request leave.</span>
        @endif
    </p>

    @if ($calendar['agenda'] !== [])
        <div class="mt-4 sm:hidden">
            <h3 class="mb-2 text-sm font-semibold">{{ $calendar['label'] }}</h3>
            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 text-sm dark:divide-white/10 dark:border-white/10">
                @foreach ($calendar['agenda'] as $row)
                    <li wire:key="leave-agenda-{{ $loop->index }}">
                        @if ($row['id'] !== null)
                            <button type="button" class="flex w-full items-baseline justify-between gap-3 px-3 py-2 text-left" wire:click="mountAction('leaveDetails', { id: {{ $row['id'] }} })">
                                <span class="min-w-0 {{ $row['mine'] ? 'font-semibold' : '' }}">{{ $row['label'] }}</span>
                                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $row['dates'] }}</span>
                            </button>
                        @else
                            <div class="flex items-baseline justify-between gap-3 px-3 py-2">
                                <span class="min-w-0 {{ $row['kind'] === 'holiday' ? 'text-amber-800 dark:text-amber-200' : '' }} {{ $row['mine'] ? 'font-semibold' : '' }}">{{ $row['label'] }}</span>
                                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $row['dates'] }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-filament::section>

{{-- A page with a table has its action modals rendered by the table. The table is hidden on this tab, and the request and details modals open from here. --}}
<x-filament-actions::modals />
