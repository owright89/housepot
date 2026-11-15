<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Welcome back')" :description="__('Log in to see what\'s due and who owes who.')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify :separator="__('or use your email')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="you@example.com"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Your password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute end-0 top-0 text-sm font-semibold" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot password?') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('Keep me logged in')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                {{ __('Log in') }}
            </flux:button>
        </form>

        @if (Route::has('register'))
            <p class="text-center text-sm text-muted">
                {{ __('New to HousePot?') }}
                <flux:link :href="route('register')" wire:navigate class="font-semibold">{{ __('Create an account') }}</flux:link>
            </p>
        @endif
    </div>

    <x-slot:aside>
        {{-- Example household (sample figures, matches the landing page) --}}
        <figure class="max-w-sm">
            <div class="overflow-hidden rounded-(--radius-card) bg-white text-[#15201B]">
                <div class="flex items-center justify-between px-5 py-3">
                    <p class="text-sm font-bold">200 Smith Street</p>
                    <p class="text-xs text-[#5B6B64]">{{ now()->format('F') }}</p>
                </div>
                <div class="space-y-1 border-t border-[#E3E8E5] px-5 py-3 text-sm">
                    <p class="flex justify-between"><span class="text-[#5B6B64]">{{ __('John owes you') }}</span><span class="font-bold text-[#157A55]">£118.20</span></p>
                    <p class="flex justify-between"><span class="text-[#5B6B64]">{{ __('You owe Steve') }}</span><span class="font-bold text-[#B23A17]">£42.50</span></p>
                </div>
            </div>
            <figcaption class="mt-2 text-xs text-white/80">{{ __('Sample figures') }}</figcaption>
        </figure>
    </x-slot:aside>
</x-layouts::auth>
