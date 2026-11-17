{{--
    A row of headline figures (<x-stat>). On phones it's one compact card with a row per figure,
    so the first screen isn't three tall cards; from sm up each figure is its own card, three across.
--}}
<div {{ $attributes->class([
    'divide-y divide-line overflow-hidden rounded-(--radius-card) border border-line bg-card',
    'sm:grid sm:grid-cols-3 sm:gap-4 sm:divide-y-0 sm:overflow-visible sm:rounded-none sm:border-0 sm:bg-transparent',
]) }}>
    {{ $slot }}
</div>
