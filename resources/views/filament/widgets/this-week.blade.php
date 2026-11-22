<x-filament-widgets::widget>
    <x-filament::section heading="This week" description="Monday to Sunday.">
        <div class="grid gap-6 md:grid-cols-3">
            @if ($showPeople)
                <div>
                    <h3 class="text-sm font-semibold">New joiners</h3>
                    <ul class="mt-2 space-y-2">
                        @forelse ($joiners as $person)
                            <li class="text-sm">
                                <span class="font-medium">{{ $person['name'] }}</span>
                                <span class="block text-gray-500 dark:text-gray-400">{{ $person['position'] ?? '—' }} · starts {{ $person['date']->format('d/m/Y') }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">Nobody joins this week.</li>
                        @endforelse
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm font-semibold">Leavers</h3>
                    <ul class="mt-2 space-y-2">
                        @forelse ($leavers as $person)
                            <li class="text-sm">
                                <span class="font-medium">{{ $person['name'] }}</span>
                                <span class="block text-gray-500 dark:text-gray-400">{{ $person['position'] ?? '—' }} · last day {{ $person['date']->format('d/m/Y') }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">Nobody leaves this week.</li>
                        @endforelse
                    </ul>
                </div>
            @endif

            @if ($showTasks)
                <div>
                    <h3 class="text-sm font-semibold">Assigned to me</h3>
                    <ul class="mt-2 space-y-2">
                        @forelse ($tasks as $task)
                            <li class="text-sm">
                                <span class="font-medium">{{ $task->name }}</span>
                                <span class="block text-gray-500 dark:text-gray-400">{{ $task->project?->name ?? '—' }} · assigned {{ $task->created_at->timezone(\App\Domain\Work\Support\WorkCalendar::timezone(auth()->user()))->format('d/m/Y') }}</span>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">No task was assigned to you this week.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
