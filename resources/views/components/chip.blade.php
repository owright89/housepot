{{--
    Small status label, e.g. Owner, Paid, Due soon. Always carries text, so meaning never relies on colour alone.
    Variants: primary (Owner, Paid), coral (Due soon, you owe), neutral (everything else).
--}}
@props(['variant' => 'neutral'])

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center whitespace-nowrap rounded-lg px-2 py-0.5 text-xs font-semibold',
    'bg-primary-soft text-primary-text' => $variant === 'primary',
    'bg-coral-soft text-coral' => $variant === 'coral',
    'bg-subtle text-muted' => $variant === 'neutral',
]) }}>
    {{ $slot }}
</span>
