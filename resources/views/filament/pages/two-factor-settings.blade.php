<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <x-form-actions>
            <x-filament::button type="submit">Save</x-filament::button>
        </x-form-actions>
    </form>
</x-filament-panels::page>
