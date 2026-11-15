<x-layouts::auth :title="__('Verify email')">
    <div class="flex flex-col gap-6">
        <div>
            <span class="flex size-12 items-center justify-center rounded-(--radius-control) bg-primary-soft text-primary-text" aria-hidden="true">
                <flux:icon.envelope variant="mini" />
            </span>

            <h1 class="mt-5 text-3xl font-extrabold tracking-tight text-ink">{{ __('Check your inbox') }}</h1>
            <p class="mt-1 text-sm text-muted">
                {{ __('We\'ve sent a verification link to') }}
                <strong class="font-bold text-ink">{{ auth()->user()->email }}</strong>.
                {{ __('Click it to confirm your email and you\'re in.') }}
            </p>
        </div>

        @if (session('status') == 'verification-link-sent')
            <flux:callout variant="success" icon="check-circle" data-test="verification-link-sent">
                <flux:callout.text>{{ __('A new link is on its way. Older links still work until they expire.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="rounded-(--radius-control) bg-subtle p-4">
            <p class="text-sm font-bold text-ink">{{ __('Can\'t see it?') }}</p>
            <p class="mt-1 text-sm text-muted">
                {{ __('Check your spam or junk folder, or give it a minute. The link expires after :minutes minutes.', ['minutes' => config('auth.verification.expire', 60)]) }}
            </p>
        </div>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full" data-test="resend-verification-button">
                {{ __('Resend email') }}
            </flux:button>
        </form>

        <div class="flex justify-center text-sm">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="cursor-pointer rounded-md font-semibold text-muted hover:text-ink" data-test="logout-button">
                    {{ __('Log out') }}
                </button>
            </form>
        </div>
    </div>

    <x-slot:aside>
        {{-- Sign-up progress: account done, email is the current step. --}}
        <ol class="space-y-4" role="list" aria-label="{{ __('Sign-up progress') }}">
            <li class="flex items-center gap-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/20" aria-hidden="true">
                    <flux:icon.check variant="micro" />
                </span>
                <span class="font-semibold text-white/85">{{ __('Create your account') }} <span class="sr-only">{{ __('(done)') }}</span></span>
            </li>
            <li class="flex items-center gap-3" aria-current="step">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white text-sm font-bold text-[#157A55]" aria-hidden="true">2</span>
                <span>
                    <span class="block font-bold">{{ __('Verify your email') }}</span>
                    <span class="block text-sm text-[#FFC3B0]">{{ __('You\'re here') }}</span>
                </span>
            </li>
            <li class="flex items-center gap-3">
                <span class="flex size-7 shrink-0 items-center justify-center rounded-lg border border-white/60 text-sm font-bold" aria-hidden="true">3</span>
                <span class="font-semibold text-white/85">{{ __('Set up or join your house') }}</span>
            </li>
        </ol>
    </x-slot:aside>
</x-layouts::auth>
