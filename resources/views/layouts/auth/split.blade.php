@props(['title' => null, 'aside' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light" style="color-scheme: light">
    <head>
        {{-- Auth pages match the light landing page; the app itself follows each user's setting. --}}
        @include('partials.head', ['title' => $title, 'forceLight' => true])
    </head>
    <body class="min-h-screen bg-card text-ink antialiased">
        <div class="grid min-h-dvh lg:grid-cols-2">
            {{-- Form --}}
            <div class="flex flex-col px-6 py-6 sm:px-10">
                <x-app-logo href="{{ route('home') }}" class="self-start text-xl" />

                <main class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10">
                    {{ $slot }}
                </main>
            </div>

            {{-- Marketing panel (large screens only) --}}
            <aside class="hidden p-4 lg:block" aria-label="{{ __('About HousePot') }}">
                <div class="flex h-full flex-col justify-center rounded-[24px] bg-primary px-12 py-16 text-white">
                    <p class="text-4xl leading-tight font-extrabold tracking-tight">
                        {{ __('Split the bills,') }}<br>
                        <span class="text-[#FFC3B0]">{{ __('keep the peace.') }}</span>
                    </p>
                    <p class="mt-4 max-w-sm text-white/85">
                        {{ __('Every shared bill in one place, with everyone\'s share worked out for you.') }}
                    </p>

                    @if ($aside)
                        <div class="mt-10">{{ $aside }}</div>
                    @endif
                </div>
            </aside>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
