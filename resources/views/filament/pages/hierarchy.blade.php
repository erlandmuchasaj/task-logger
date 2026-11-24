<x-filament-panels::page>
    @php
        $roots = $this->roots();
        $legend = \App\Domain\People\Support\OrgTree::legend($roots);
        $initiallyOpen = \App\Domain\People\Support\OrgTree::initiallyOpen($roots);
    @endphp

    <x-filament::section heading="Reporting lines" description="Everyone who is active, under the person they report to. Click a card to show or hide its team, and a name to open the profile.">
        <div x-data="{ open: @js($initiallyOpen), zoom: 1, all(state) { Object.keys(this.open).forEach((id) => { this.open[id] = state }) }, zoomBy(step) { this.zoom = Math.min(1.5, Math.max(0.5, Math.round((this.zoom + step) * 10) / 10)) } }">
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <x-filament::button color="gray" size="sm" x-on:click="all(true)">Expand all</x-filament::button>
                <x-filament::button color="gray" size="sm" x-on:click="all(false)">Collapse all</x-filament::button>

                <div class="flex items-center gap-1 sm:ml-auto">
                    <x-filament::button color="gray" size="sm" aria-label="Zoom out" x-on:click="zoomBy(-0.1)">−</x-filament::button>
                    <x-filament::button color="gray" size="sm" aria-label="Reset zoom" x-on:click="zoom = 1"><span x-text="Math.round(zoom * 100) + '%'">100%</span></x-filament::button>
                    <x-filament::button color="gray" size="sm" aria-label="Zoom in" x-on:click="zoomBy(0.1)">+</x-filament::button>
                </div>
            </div>

            @if ($legend !== [])
                <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1" aria-label="Departments">
                    @foreach ($legend as $department)
                        <li class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <span class="h-3 w-3 shrink-0 rounded-sm {{ $department['color'] ? '' : 'bg-gray-300 dark:bg-white/20' }}" @if ($department['color']) style="background-color: {{ $department['color'] }}" @endif></span>
                            {{ $department['name'] }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="overflow-x-auto pb-6" x-init="$nextTick(() => { $el.scrollLeft = ($el.scrollWidth - $el.clientWidth) / 2 })">
                <div class="w-max min-w-full" x-bind:style="{ zoom: zoom }">
                    <ul class="flex justify-center">
                        @forelse ($roots as $node)
                            @include('filament.pages.hierarchy-node', ['node' => $node, 'isRoot' => true])
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">Nobody is active yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
