<x-filament-panels::page>
    @php($roots = $this->roots())

    <x-filament::section heading="Reporting lines" description="Everyone who is active, under the person they report to.">
        <div x-data="{ open: {}, all(state) { document.querySelectorAll('[data-org-node]').forEach((el) => { this.open[el.dataset.orgNode] = state }) } }">
            <div class="mb-4 flex flex-wrap gap-2">
                <x-filament::button color="gray" size="sm" x-on:click="all(true)">Expand all</x-filament::button>
                <x-filament::button color="gray" size="sm" x-on:click="all(false)">Collapse all</x-filament::button>
            </div>

            <ul class="space-y-1">
                @forelse ($roots as $node)
                    @include('filament.pages.hierarchy-node', ['node' => $node])
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">Nobody is active yet.</li>
                @endforelse
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>
