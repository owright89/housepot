@props([
    'sidebar' => false,
])

{{--
    Wordmark: "House" in primary green, "Pot" in coral. Inside a collapsed desktop sidebar it
    shrinks to "HP", and (like Flux's sidebar brand) fades out while the sidebar is hovered so
    the expand button can sit in its place. Those variants never match outside the sidebar.
--}}
<a
    aria-label="HousePot"
    {{ $attributes->class([
        'inline-flex items-center rounded-(--radius-control) px-1 text-2xl font-extrabold tracking-tight',
        'in-data-flux-sidebar-collapsed-desktop:w-10 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:px-0 in-data-flux-sidebar-collapsed-desktop:text-xl',
        'in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-0',
    ]) }}
>
    <span class="in-data-flux-sidebar-collapsed-desktop:hidden" aria-hidden="true"><span class="text-primary-text">House</span><span class="text-coral">Pot</span></span>
    <span class="hidden in-data-flux-sidebar-collapsed-desktop:inline" aria-hidden="true"><span class="text-primary-text">H</span><span class="text-coral">P</span></span>
</a>
