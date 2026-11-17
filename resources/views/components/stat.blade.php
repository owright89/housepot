{{--
    One headline figure inside <x-stat-group>: a label, the value, and an optional caption.
    Phones: a row (label and caption left, value right). sm and up: a card.
    Extra attributes (class, data-test) go on the value.
--}}
@props(['label', 'caption' => null])

<div class="flex items-center justify-between gap-4 px-4 py-3 sm:block sm:rounded-(--radius-card) sm:border sm:border-line sm:bg-card sm:p-6">
    <div class="min-w-0">
        <p class="text-sm text-muted">{{ $label }}</p>
        @if ($caption)
            <p class="mt-0.5 text-xs text-muted sm:hidden">{{ $caption }}</p>
        @endif
    </div>

    <p {{ $attributes->class('shrink-0 text-xl font-extrabold sm:mt-1 sm:text-3xl') }}>{{ $slot }}</p>

    @if ($caption)
        <p class="mt-1 hidden text-sm text-muted sm:block">{{ $caption }}</p>
    @endif
</div>
