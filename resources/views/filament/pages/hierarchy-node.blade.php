<li data-org-node="{{ $node->id }}" wire:key="org-{{ $node->id }}">
    <div class="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-white/5">
        @if ($node->children !== [])
            <button type="button" class="flex h-6 w-6 shrink-0 items-center justify-center rounded text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10"
                x-on:click="open[{{ $node->id }}] = ! open[{{ $node->id }}]"
                x-bind:aria-expanded="(!! open[{{ $node->id }}]).toString()"
                aria-label="Show or hide the team of {{ $node->name }}">
                <span class="text-xs transition-transform" x-bind:class="open[{{ $node->id }}] ? 'rotate-90' : ''">▶</span>
            </button>
        @else
            <span class="h-6 w-6 shrink-0"></span>
        @endif

        @if ($node->avatarUrl)
            <img src="{{ $node->avatarUrl }}" alt="" class="h-9 w-9 shrink-0 rounded-full object-cover" loading="lazy">
        @else
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ $node->initials }}</span>
        @endif

        <div class="min-w-0">
            <div class="truncate text-sm font-medium">{{ $node->name }}</div>
            <div class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $node->position ?? '—' }}@if ($node->children !== []) · {{ $node->descendants() }} {{ $node->descendants() === 1 ? 'person' : 'people' }} below @endif</div>
        </div>
    </div>

    @if ($node->children !== [])
        <ul x-show="open[{{ $node->id }}]" x-cloak class="ml-3 space-y-1 border-l border-gray-200 pl-2 dark:border-white/10 sm:ml-6 sm:pl-4">
            @foreach ($node->children as $child)
                @include('filament.pages.hierarchy-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
