<?php

use Livewire\Component;

new class extends Component {}; ?>

<x-settings-card :heading="__('Delete account')" :subheading="__('Permanently delete your account. You need to leave your households first.')">
    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button variant="danger" data-test="delete-user-button">
            {{ __('Delete account') }}
        </flux:button>
    </flux:modal.trigger>

    <livewire:pages::settings.delete-user-modal />
</x-settings-card>
