{{--
    Shows whether live updates are connected. Reads the Echo (Reverb) connection state and
    cleans up its listener when the page is navigated away from.
--}}
<span
    x-data="{
        state: window.Echo?.connector?.pusher?.connection?.state ?? 'connecting',
        update(change) { this.state = change.current },
        init() { window.Echo?.connector?.pusher?.connection?.bind('state_change', this.update = this.update.bind(this)) },
        destroy() { window.Echo?.connector?.pusher?.connection?.unbind('state_change', this.update) },
    }"
    role="status"
    {{ $attributes->class('inline-flex items-center gap-1.5 rounded-lg px-2 py-0.5 text-xs font-semibold') }}
    :class="state === 'connected' ? 'bg-primary-soft text-primary-text' : 'bg-subtle text-muted'"
    data-test="live-indicator"
>
    <span class="size-1.5 rounded-full" :class="state === 'connected' ? 'bg-primary-text' : 'bg-muted'" aria-hidden="true"></span>
    <span x-text="state === 'connected' ? '{{ __('Live') }}' : '{{ __('Reconnecting…') }}'">{{ __('Live') }}</span>
</span>
