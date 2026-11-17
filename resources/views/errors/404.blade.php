{{--
    404: "This page has moved out". Standalone (no app layout): unknown URLs are matched before
    the session starts, so this can't rely on knowing who's logged in. "Back to dashboard" still
    works for guests, it just goes via login.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('Not found')])
    </head>
    <body class="flex min-h-screen flex-col bg-canvas text-ink antialiased">
        <main class="flex flex-1 flex-col items-center justify-center px-6 pb-24 text-center">
            {{-- "4 [house] 4": the house is the zero, with a coral door --}}
            <p class="flex items-center gap-3 text-[6rem] leading-none font-extrabold tracking-tight text-primary-text sm:text-[8rem]" role="img" aria-label="404">
                <span aria-hidden="true">4</span>
                <svg class="size-[5.5rem] sm:size-[7.25rem]" viewBox="0 0 96 96" fill="none" aria-hidden="true">
                    <path d="M14 44 48 14l34 30" stroke="currentColor" stroke-width="9" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M22 40v38a6 6 0 0 0 6 6h40a6 6 0 0 0 6-6V40" class="fill-primary-soft" stroke="currentColor" stroke-width="9" stroke-linejoin="round" />
                    <path d="M41 84V64a7 7 0 0 1 14 0v20z" class="fill-coral" />
                </svg>
                <span aria-hidden="true">4</span>
            </p>

            <h1 class="mt-6 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('This page has moved out') }}</h1>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <flux:button variant="primary" icon="home" :href="route('dashboard')">{{ __('Back to dashboard') }}</flux:button>
                <flux:button :href="route('home')">{{ __('Go to homepage') }}</flux:button>
            </div>

            <p class="mt-6 text-xs text-muted">{{ __('Error 404 · Page not found') }}</p>
        </main>
    </body>
</html>
