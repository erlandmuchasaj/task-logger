<x-filament-panels::page>
    @php($details = $this->details)

    <x-filament::section>
        <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
            @if ($details->avatarUrl)
                <img src="{{ $details->avatarUrl }}" alt="" class="h-24 w-24 shrink-0 rounded-full object-cover ring-4 ring-gray-200 dark:ring-white/10"
                    @if ($details->color) style="--tw-ring-color: {{ $details->color }}" @endif>
            @else
                <span class="flex h-24 w-24 shrink-0 items-center justify-center rounded-full bg-primary-100 text-2xl font-semibold text-primary-700 ring-4 ring-gray-200 dark:bg-primary-500/20 dark:text-primary-300 dark:ring-white/10"
                    @if ($details->color) style="--tw-ring-color: {{ $details->color }}" @endif>{{ $details->initials }}</span>
            @endif

            <dl class="grid flex-1 grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Name</dt>
                    <dd class="text-sm font-medium">{{ $details->name }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Job title</dt>
                    <dd class="text-sm font-medium">{{ $details->position ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Department</dt>
                    <dd class="flex items-center gap-2 text-sm font-medium">
                        @if ($details->department && $details->color)
                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $details->color }}"></span>
                        @endif
                        {{ $details->department ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Manager</dt>
                    <dd class="text-sm font-medium">
                        @if ($details->managerId !== null)
                            <a href="{{ \App\Filament\Pages\ViewPerson::getUrl(['user' => $details->managerId]) }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $details->managerName }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Email</dt>
                    <dd class="break-all text-sm font-medium"><a href="mailto:{{ $details->email }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $details->email }}</a></dd>
                </div>
                @if ($details->showsPrivate)
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Phone</dt>
                        <dd class="text-sm font-medium">{{ $details->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">University</dt>
                        <dd class="text-sm font-medium">{{ $details->university ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </x-filament::section>
</x-filament-panels::page>
