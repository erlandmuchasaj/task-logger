<x-filament-panels::page>
    <form wire:submit="save" class="max-w-xl">
        {{ $this->form }}

        <x-form-actions>
            <x-filament::button type="submit">Save password</x-filament::button>
        </x-form-actions>
    </form>

    <form method="POST" action="{{ filament()->getLogoutUrl() }}">
        @csrf
        <x-filament::link tag="button" type="submit" color="gray" size="sm">Not you? Sign out</x-filament::link>
    </form>
</x-filament-panels::page>
