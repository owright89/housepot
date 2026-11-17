{{--
    Sidebar dropdown showing the current household and letting the user jump to another.
    In a collapsed desktop sidebar it shrinks to a square with the household's initials.
--}}
@props(['current'])

@php($initials = Str::upper(collect(preg_split('/\s+/', trim($current->name)))->take(2)->map(fn ($word) => Str::substr($word, 0, 1))->implode('')))

<flux:dropdown position="bottom" align="start" class="w-full">
    <button
        type="button"
        class="flex w-full items-center justify-between gap-2 rounded-(--radius-control) border border-line bg-card px-3 py-2 text-start transition-colors hover:bg-subtle in-data-flux-sidebar-collapsed-desktop:size-10 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:p-0"
        title="{{ __('Household: :name', ['name' => $current->name]) }}"
        data-test="household-switcher"
    >
        <span class="hidden text-xs font-extrabold text-ink in-data-flux-sidebar-collapsed-desktop:inline" aria-hidden="true">{{ $initials }}</span>
        <span class="sr-only">{{ __('Household: :name', ['name' => $current->name]) }}</span>

        <span class="min-w-0 in-data-flux-sidebar-collapsed-desktop:hidden" aria-hidden="true">
            <span class="block text-[11px] font-medium text-muted">{{ __('Household') }}</span>
            <span class="block truncate text-sm font-bold text-ink">{{ $current->name }}</span>
        </span>
        <flux:icon.chevron-up-down variant="micro" class="shrink-0 text-muted in-data-flux-sidebar-collapsed-desktop:hidden" />
    </button>

    <flux:menu class="min-w-56">
        @foreach (auth()->user()->households()->orderBy('name')->get() as $household)
            <flux:menu.item :href="route('households.show', $household)" wire:navigate :icon="$household->is($current) ? 'check' : null">
                {{ $household->name }}
            </flux:menu.item>
        @endforeach

        <flux:menu.separator />

        <flux:menu.item :href="route('households.index')" icon="squares-2x2" wire:navigate>
            {{ __('All households') }}
        </flux:menu.item>
        <flux:menu.item :href="route('households.index')" icon="plus" wire:navigate>
            {{ __('New household') }}
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
