@props([
    'sidebar' => false,
])

{{-- Wordmark: "House" in primary green, "Pot" in coral. --}}
<a {{ $attributes->class('inline-flex items-center rounded-(--radius-control) px-1 text-2xl font-extrabold tracking-tight') }}>
    <span class="text-primary-text">House</span><span class="text-coral">Pot</span>
</a>
