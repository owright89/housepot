<x-layouts::auth :title="__('Create account')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create your account')" :description="__('Then set up your house or join the one you\'ve been invited to.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="name"
                :label="__('Your name')"
                :description:trailing="__('This is how your housemates will see you.')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('e.g. Sam Taylor')"
            />

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="you@example.com"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                :description:trailing="app()->isProduction()
                    ? __('At least 12 characters, with upper and lower case letters, a number and a symbol.')
                    : __('At least 8 characters.')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Create a password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Type it again')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                {{ __('Create account') }}
            </flux:button>
        </form>

        <p class="text-center text-sm text-muted">
            {{ __('Already have an account?') }}
            <flux:link :href="route('login')" wire:navigate class="font-semibold">{{ __('Log in') }}</flux:link>
        </p>
    </div>

    <x-slot:aside>
        <ol class="space-y-4" role="list">
            @foreach ([
                [__('Set up your house'), __('Or join with your housemate\'s invite link.')],
                [__('Add bills as they land'), __('Split equally or by custom amounts.')],
                [__('Settle up in one go'), __('See exactly who owes who.')],
            ] as $i => [$heading, $text])
                <li class="flex gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white text-sm font-bold text-[#157A55]" aria-hidden="true">{{ $i + 1 }}</span>
                    <span>
                        <span class="block font-bold">{{ $heading }}</span>
                        <span class="block text-sm text-white/85">{{ $text }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
    </x-slot:aside>
</x-layouts::auth>
