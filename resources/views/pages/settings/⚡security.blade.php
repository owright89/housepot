<?php

use App\Concerns\PasswordValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    /**
     * Whether the user has confirmed their password recently (config auth.password_timeout).
     * Until then the page shows an inline "Confirm it's you" box instead of the settings, replacing
     * the redirect to /user/confirm-password that the password.confirm middleware used to do.
     */
    #[Locked]
    public bool $unlocked = false;

    public string $confirmablePassword = '';

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $this->unlocked = $this->passwordRecentlyConfirmed();

        if (! $this->unlocked) {
            // A passkey confirmation redirects to the "intended" URL, so bring it back here.
            session()->put('url.intended', route('security.edit'));
        }

        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    /**
     * Confirm the user's password inline, then show the security settings. Records the same
     * session timestamp as Laravel's password.confirm, so Fortify's own confirm-protected
     * endpoints (passkeys, two-factor) accept it too.
     */
    public function confirmPassword(): void
    {
        $this->validate(['confirmablePassword' => ['required', 'string']]);

        $key = 'confirm-password:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->reset('confirmablePassword');

            throw ValidationException::withMessages([
                'confirmablePassword' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $valid = Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->confirmablePassword,
        ]);

        $this->reset('confirmablePassword');

        if (! $valid) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['confirmablePassword' => __('auth.password')]);
        }

        RateLimiter::clear($key);
        session()->put('auth.password_confirmed_at', time());
        session()->forget('url.intended');

        $this->unlocked = true;

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    private function passwordRecentlyConfirmed(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return time() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }

    /**
     * Guard for every action on this page: if the confirmation has expired since the page loaded,
     * lock it again instead of acting. (Livewire doesn't re-run route middleware on updates.)
     */
    private function ensureUnlocked(): bool
    {
        $this->unlocked = $this->passwordRecentlyConfirmed();

        return $this->unlocked;
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        if (! $this->ensureUnlocked()) {
            return;
        }

        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        if (! $this->ensureUnlocked()) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->ensureUnlocked() || ! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        if (! $this->ensureUnlocked()) {
            return;
        }

        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Security settings') }}</flux:heading>

    @unless ($unlocked)
        {{-- Inline password confirmation, inside Settings rather than on a separate page --}}
        <x-pages::settings.layout>
            <x-settings-card :heading="__('Confirm it\'s you')" :subheading="__('Security settings need your password. You won\'t be asked again for a few hours.')">
            <div class="space-y-6" data-test="security-locked">
                <x-passkey-verify
                    options-route="passkey.confirm-options"
                    submit-route="passkey.confirm"
                    :label="__('Confirm with passkey')"
                    :loading-label="__('Confirming...')"
                    :separator="__('Or confirm with password')"
                />

                <form wire:submit="confirmPassword" class="space-y-6">
                    <flux:input
                        wire:model="confirmablePassword"
                        :label="__('Password')"
                        type="password"
                        required
                        autocomplete="current-password"
                        viewable
                    />

                    <flux:button variant="primary" type="submit" data-test="confirm-password-button">
                        {{ __('Confirm') }}
                    </flux:button>
                </form>
            </div>
            </x-settings-card>
        </x-pages::settings.layout>
    @else
    <x-pages::settings.layout>
        <x-settings-card :heading="__('Update password')" :subheading="__('Use a long, random password to keep your account secure.')">
        <form method="POST" wire:submit="updatePassword" class="space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button variant="primary" type="submit" data-test="update-password-button">
                {{ __('Save') }}
            </flux:button>
        </form>
        </x-settings-card>

        @if ($canManageTwoFactor)
            <x-settings-card :heading="__('Two-factor authentication')" :subheading="__('Ask for a code from your authenticator app when you log in.')">
                <div class="flex w-full flex-col space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:button
                                    variant="danger"
                                    wire:click="disable"
                                >
                                    {{ __('Disable 2FA') }}
                                </flux:button>
                            </div>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </x-settings-card>
        @endif

        @if ($canManagePasskeys)
            <x-settings-card :heading="__('Passkeys')" :subheading="__('Manage your passkeys for passwordless sign-in.')">
                <div class="flex w-full flex-col space-y-6 text-sm" wire:cloak>
                    <div class="overflow-hidden rounded-(--radius-control) border border-line">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-line' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-(--radius-control) bg-subtle">
                                        <flux:icon.key class="size-5 text-muted" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-xs text-muted">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-coral! hover:bg-coral-soft!"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-3 flex size-10 items-center justify-center rounded-(--radius-control) bg-subtle">
                                    <flux:icon.key class="size-5 text-muted" />
                                </div>
                                <p class="font-bold text-ink">{{ __('No passkeys yet') }}</p>
                                <flux:text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </x-settings-card>
        @endif
    </x-pages::settings.layout>
    @endunless

    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
