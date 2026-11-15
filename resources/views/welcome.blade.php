<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light" style="color-scheme: light">
    <head>
        @include('partials.head', ['title' => __('Split the bills, keep the peace'), 'forceLight' => true])
    </head>
    <body class="min-h-screen bg-card text-ink antialiased">
        @php($startUrl = auth()->check() ? route('households.index') : (Route::has('register') ? route('register') : route('login')))

        {{-- Header --}}
        <header class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-5">
            <x-app-logo href="{{ route('home') }}" />

            <nav class="flex items-center gap-2 sm:gap-4" aria-label="{{ __('Main') }}">
                @auth
                    <flux:button variant="primary" :href="route('dashboard')">{{ __('Dashboard') }}</flux:button>
                @else
                    <a href="{{ route('login') }}" class="rounded-(--radius-control) px-2 py-2 text-sm font-semibold text-ink hover:text-primary-text">{{ __('Log in') }}</a>
                    <flux:button variant="primary" :href="$startUrl">{{ __('Get started') }}</flux:button>
                @endauth
            </nav>
        </header>

        <main>
            {{-- Hero --}}
            <section class="mx-auto grid max-w-5xl items-center gap-12 px-6 pt-10 pb-20 lg:grid-cols-2 lg:pt-16">
                <div>
                    <x-chip variant="primary">{{ __('For house shares and flatmates') }}</x-chip>

                    <h1 class="mt-4 text-5xl leading-[1.05] font-extrabold tracking-tight text-ink sm:text-6xl">
                        {{ __('Split the bills,') }}<br>
                        <span class="text-coral">{{ __('keep the peace.') }}</span>
                    </h1>

                    <p class="mt-5 max-w-md text-lg text-muted">
                        {{ __('Rent, energy, broadband, the lot. Add each bill once and HousePot works out everyone\'s share, so nobody has to chase anyone.') }}
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <flux:button variant="primary" icon:trailing="arrow-right" :href="$startUrl">{{ __('Create your household') }}</flux:button>

                        <flux:modal.trigger name="been-invited">
                            <flux:button>{{ __('I\'ve been invited') }}</flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>

                {{-- Example household (static sample data, not a real account) --}}
                <figure>
                    {{-- The green panel sits inside this wrapper only, so it can't cover the caption below. --}}
                    <div class="relative pt-8 pr-8 pb-16">
                        <div class="absolute inset-y-0 right-0 left-8 rounded-[24px] bg-primary" aria-hidden="true"></div>

                        <div class="relative overflow-hidden rounded-(--radius-card) border border-line bg-card">
                            <div class="flex items-start justify-between gap-4 px-5 py-4">
                                <div>
                                    <p class="font-bold text-ink">200 Smith Street</p>
                                    <p class="text-xs text-muted">{{ __('November · 3 housemates') }}</p>
                                </div>
                                <p class="text-lg font-extrabold text-ink">£1,968.50</p>
                            </div>

                            <ul class="divide-y divide-line border-t border-line text-sm" role="list">
                                <li class="flex items-center justify-between gap-3 px-5 py-3">
                                    <span class="font-semibold">{{ __('Rent') }}</span>
                                    <span class="flex items-center gap-3"><x-bill-status status="upcoming" /><span class="w-20 text-end font-bold">£1,650.00</span></span>
                                </li>
                                <li class="flex items-center justify-between gap-3 px-5 py-3">
                                    <span class="font-semibold">{{ __('Energy') }}</span>
                                    <span class="flex items-center gap-3"><x-bill-status status="due" /><span class="w-20 text-end font-bold">£96.40</span></span>
                                </li>
                                <li class="flex items-center justify-between gap-3 px-5 py-3">
                                    <span class="font-semibold">{{ __('Water') }}</span>
                                    <span class="flex items-center gap-3"><x-bill-status status="paid" /><span class="w-20 text-end font-bold">£38.10</span></span>
                                </li>
                            </ul>

                            <div class="space-y-1 border-t border-line bg-subtle px-5 py-3 text-sm">
                                <p class="flex justify-between"><span class="text-muted">{{ __('John owes you') }}</span><span class="font-bold text-primary-text">£118.20</span></p>
                                <p class="flex justify-between"><span class="text-muted">{{ __('You owe Steve') }}</span><span class="font-bold text-coral">£42.50</span></p>
                            </div>
                        </div>

                        <div class="absolute bottom-4 left-0 flex items-center gap-3 rounded-(--radius-control) border border-line bg-card px-3 py-2 shadow-lg" aria-hidden="true">
                            <span class="flex size-7 items-center justify-center rounded-lg bg-primary-soft text-primary-text">
                                <flux:icon.check variant="micro" />
                            </span>
                            <span>
                                <span class="block text-xs font-bold text-ink">{{ __('John paid you £35.00') }}</span>
                                <span class="block text-[11px] text-muted">{{ __('Just now') }}</span>
                            </span>
                        </div>
                    </div>

                    <figcaption class="mt-3 text-end text-xs text-muted">{{ __('Example household with sample figures') }}</figcaption>
                </figure>
            </section>

            {{-- How it works --}}
            <section id="how-it-works" class="scroll-mt-6 border-y border-line bg-canvas">
                <div class="mx-auto max-w-5xl px-6 py-20">
                    <h2 class="text-3xl font-extrabold tracking-tight text-ink">{{ __('Sorted in three steps') }}</h2>

                    <ol class="mt-8 grid gap-4 md:grid-cols-3" role="list">
                        <li class="rounded-(--radius-card) border border-line bg-card p-6">
                            <span class="flex size-10 items-center justify-center rounded-(--radius-control) bg-primary-soft text-primary-text" aria-hidden="true">
                                <flux:icon.home variant="mini" />
                            </span>
                            <h3 class="mt-4 font-bold text-ink">{{ __('Set up your house') }}</h3>
                            <p class="mt-2 text-sm text-muted">{{ __('Create a household and invite your housemates with a link. Takes about a minute.') }}</p>
                        </li>
                        <li class="rounded-(--radius-card) border border-line bg-card p-6">
                            <span class="flex size-10 items-center justify-center rounded-(--radius-control) bg-coral-soft text-coral" aria-hidden="true">
                                <flux:icon.document-text variant="mini" />
                            </span>
                            <h3 class="mt-4 font-bold text-ink">{{ __('Add bills as they land') }}</h3>
                            <p class="mt-2 text-sm text-muted">{{ __('Log who paid and how to split it, equally or by custom amounts. Everyone sees it straight away.') }}</p>
                        </li>
                        <li class="rounded-(--radius-card) border border-line bg-card p-6">
                            <span class="flex size-10 items-center justify-center rounded-(--radius-control) bg-primary-soft text-primary-text" aria-hidden="true">
                                <flux:icon.arrows-right-left variant="mini" />
                            </span>
                            <h3 class="mt-4 font-bold text-ink">{{ __('Settle up in one go') }}</h3>
                            <p class="mt-2 text-sm text-muted">{{ __('HousePot nets everything off, so each person makes the fewest payments possible.') }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            {{-- Call to action --}}
            <section class="mx-auto max-w-5xl px-6 py-20">
                <div class="flex flex-col gap-6 rounded-[24px] bg-primary px-8 py-10 text-white sm:flex-row sm:items-center sm:justify-between sm:px-10">
                    <div>
                        <h2 class="text-3xl font-extrabold tracking-tight">{{ __('No more “who paid for the Wi-Fi?”') }}</h2>
                        <p class="mt-2 text-white/85">{{ __('Start your household now and send the link to the group chat.') }}</p>
                    </div>
                    <a href="{{ $startUrl }}" class="inline-flex h-11 shrink-0 items-center gap-2 self-start rounded-(--radius-control) bg-white px-5 text-sm font-bold text-[#15201B] hover:bg-white/90 focus-visible:outline-white sm:self-auto">
                        {{ __('Get started') }}
                        <flux:icon.arrow-right variant="micro" aria-hidden="true" />
                    </a>
                </div>
            </section>
        </main>

        <footer class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 pb-10">
            <x-app-logo href="{{ route('home') }}" class="text-lg" />
            <p class="text-sm text-muted">{{ __('A portfolio project built with Laravel, Livewire and Reverb.') }}</p>
        </footer>

        {{-- "I've been invited" explainer --}}
        <flux:modal name="been-invited" class="w-full max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Joining a household') }}</flux:heading>
                <flux:text>{{ __('Open the invite link your housemate sent you. If you\'re not signed in yet, you\'ll be asked to log in or create an account first, then you\'ll join the household straight away.') }}</flux:text>
                <flux:text>{{ __('Links work once and expire after 7 days. If yours has stopped working, ask the owner for a new one.') }}</flux:text>
                <div class="flex justify-end gap-2">
                    @guest
                        <flux:button :href="route('login')">{{ __('Log in') }}</flux:button>
                    @endguest
                    <flux:modal.close>
                        <flux:button variant="primary">{{ __('Got it') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        </flux:modal>

        @fluxScripts
    </body>
</html>
