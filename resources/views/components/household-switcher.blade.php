{{-- Sidebar dropdown showing the current household and letting the user jump to another. --}}
@props(['current'])

<flux:dropdown position="bottom" align="start" class="w-full">
    <button
        type="button"
        class="flex w-full items-center justify-between gap-2 rounded-(--radius-control) border border-line bg-card px-3 py-2 text-start transition-colors hover:bg-subtle"
        data-test="household-switcher"
    >
        <span class="min-w-0">
            <span class="block text-[11px] font-medium text-muted">{{ __('Household') }}</span>
            <span class="block truncate text-sm font-bold text-ink">{{ $current->name }}</span>
        </span>
        <flux:icon.chevron-up-down variant="micro" class="shrink-0 text-muted" />
    </button>

    <flux:menu class="min-w-56">
        @foreach (auth()->user()->households()->orderBy('name')->get() as $household)
            <flux:menu.item :href="route('households.show', $household)" wire:navigate :icon="$household->is($current) ? 'check' : null">
                {{ $household->name }}
            </flux:menu.item>
        @endforeach

        <flux:menu.separator />

        <flux:menu.item :href="route('households.index')" icon="plus" wire:navigate>
            {{ __('New household') }}
        </flux:menu.item>
    </flux:menu>
</flux:dropdown>
