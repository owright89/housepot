{{-- One settings section: a card with a heading and a short description, like the app's other cards. --}}
@props(['heading', 'subheading' => null])

<flux:card {{ $attributes }}>
    <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
    @if ($subheading)
        <flux:text class="mt-1">{{ $subheading }}</flux:text>
    @endif

    <div class="mt-6">
        {{ $slot }}
    </div>
</flux:card>
