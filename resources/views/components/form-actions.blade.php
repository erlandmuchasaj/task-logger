{{-- The button row under a custom page form: the same gap above it on every screen. --}}
<div {{ $attributes->class('mt-6 flex flex-wrap items-center gap-3') }}>
    {{ $slot }}
</div>
