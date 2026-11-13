@php
    $pinned = $this->pinnedTasks();
    $total = $this->dayTotalMinutes();
@endphp

<x-filament-panels::page>
    @if ($pinned->isNotEmpty())
        <x-filament::section heading="Pinned tasks" description="Log time here every day until the task is completed or cancelled.">
            <div class="flex flex-wrap gap-3">
                @foreach ($pinned as $task)
                    <x-filament::button color="warning" outlined wire:click="useTask({{ $task->id }})" wire:key="pinned-{{ $task->id }}">
                        📌 {{ $task->project->name }} - {{ $task->name }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Log time">
        <form wire:submit="save">
            {{ $this->form }}

            <x-form-actions>
                <x-filament::button type="submit">Log time</x-filament::button>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    Logged on this day: {{ intdiv($total, 60) }}h {{ $total % 60 }}m of 24h
                </span>
            </x-form-actions>
        </form>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
