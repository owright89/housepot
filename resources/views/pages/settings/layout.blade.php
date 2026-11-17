<div class="flex items-start max-md:flex-col">
    {{-- Desktop: a vertical menu beside the form --}}
    <div class="me-10 hidden w-[220px] pb-4 md:block">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    {{-- Phones: the same links as a row of tabs, so the form starts near the top --}}
    <flux:navbar class="-mx-2 w-full overflow-x-auto md:hidden" aria-label="{{ __('Settings') }}" data-test="settings-tabs">
        <flux:navbar.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navbar.item>
        <flux:navbar.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navbar.item>
        <flux:navbar.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navbar.item>
    </flux:navbar>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
