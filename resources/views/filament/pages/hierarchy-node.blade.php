@php($hasTeam = $node->children !== [])

<li data-org-node="{{ $node->id }}" wire:key="org-{{ $node->id }}"
    @class([
        'relative flex flex-col items-center px-2',
        'pt-6 before:absolute before:right-1/2 before:top-0 before:h-6 before:w-1/2 before:border-t before:border-gray-300 dark:before:border-white/20' => ! $isRoot,
        'after:absolute after:left-1/2 after:top-0 after:h-6 after:w-1/2 after:border-l after:border-t after:border-gray-300 dark:after:border-white/20' => ! $isRoot,
        'first:before:border-0 first:after:rounded-tl-lg last:before:rounded-tr-lg last:before:border-r last:after:border-0' => ! $isRoot,
        'only:pt-0 only:before:hidden only:after:hidden' => ! $isRoot,
    ])>
    <div @class([
            'relative flex w-56 items-center gap-3 rounded-lg border-2 bg-white p-3 text-left shadow-sm dark:bg-gray-900',
            'border-gray-200 dark:border-white/10' => $node->color === null,
            'cursor-pointer hover:shadow-md' => $hasTeam,
        ])
        @if ($node->color !== null) style="border-color: {{ $node->color }}" @endif
        @if ($hasTeam) x-on:click="open[{{ $node->id }}] = ! open[{{ $node->id }}]" @endif>
        @if ($node->avatarUrl)
            <img src="{{ $node->avatarUrl }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover" loading="lazy">
        @else
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ $node->initials }}</span>
        @endif

        <div class="min-w-0">
            <a href="{{ \App\Filament\Pages\ViewPerson::getUrl(['user' => $node->id]) }}" x-on:click.stop class="block truncate text-sm font-bold hover:underline focus:underline">{{ $node->name }}</a>
            <div class="truncate text-sm">{{ $node->position ?? '—' }}</div>
            <div class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $node->department ?? '—' }}</div>
        </div>

        @if ($hasTeam)
            <button type="button"
                class="absolute -bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-md border border-gray-300 bg-white px-2 py-0.5 text-xs text-gray-600 shadow-sm hover:bg-gray-50 dark:border-white/20 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                x-on:click.stop="open[{{ $node->id }}] = ! open[{{ $node->id }}]"
                x-bind:aria-expanded="(!! open[{{ $node->id }}]).toString()"
                aria-label="Show or hide the {{ $node->descendants() }} {{ $node->descendants() === 1 ? 'person' : 'people' }} below {{ $node->name }}">
                {{ $node->descendants() }}
                <x-filament::icon icon="heroicon-m-chevron-down" class="h-3 w-3 transition-transform" x-bind:class="open[{{ $node->id }}] ? 'rotate-180' : ''" />
            </button>
        @endif
    </div>

    @if ($hasTeam)
        <ul x-show="open[{{ $node->id }}]" x-cloak
            class="relative flex justify-center pt-6 before:absolute before:left-1/2 before:top-0 before:h-6 before:border-l before:border-gray-300 dark:before:border-white/20">
            @foreach ($node->children as $child)
                @include('filament.pages.hierarchy-node', ['node' => $child, 'isRoot' => false])
            @endforeach
        </ul>
    @endif
</li>
