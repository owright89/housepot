{{--
    Settings shell: a menu and a column of cards (<x-settings-card>), in the app's style.
    Menu: segmented control on phones (like the bills filter), sidebar-style list from md up.
--}}
@php
    $items = [
        ['route' => 'profile.edit', 'label' => __('Profile'), 'icon' => 'user'],
        ['route' => 'security.edit', 'label' => __('Security'), 'icon' => 'shield-check'],
        ['route' => 'appearance.edit', 'label' => __('Appearance'), 'icon' => 'swatch'],
    ];
@endphp

<div class="flex flex-col gap-6 md:flex-row md:items-start">
    <nav
        aria-label="{{ __('Settings') }}"
        class="flex gap-1 rounded-(--radius-control) bg-subtle p-1 md:w-56 md:shrink-0 md:flex-col md:rounded-(--radius-card) md:border md:border-line md:bg-card md:p-2"
        data-test="settings-nav"
    >
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['route']))
            <a
                href="{{ route($item['route']) }}"
                wire:navigate
                @if ($active) aria-current="page" @endif
                @class([
                    'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold whitespace-nowrap transition-colors',
                    'md:h-11 md:flex-none md:justify-start md:rounded-(--radius-control) md:py-0',
                    'bg-card text-ink shadow-xs md:bg-primary-soft md:text-primary-text md:shadow-none' => $active,
                    'text-muted hover:text-ink md:hover:bg-subtle' => ! $active,
                ])
            >
                <flux:icon :icon="$item['icon']" variant="mini" class="hidden size-4 md:block" aria-hidden="true" />
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="min-w-0 flex-1 space-y-6 md:max-w-2xl">
        {{ $slot }}
    </div>
</div>
