{{--
    Who else has this household open (Reverb presence channel), and who is adding or editing a
    bill right now (client whispers on the same channel). Purely client-side state: nothing is stored.

    - Avatars are rendered here for every other member and shown or hidden by who's online, so
      names and colours come from the server.
    - The bill form tells this component when the viewer types (`household-typing` window event)
      or finishes (`household-typing-stopped`); it whispers that to everyone else. One component
      owns the channel, because Echo.leave() would disconnect every listener on it.
    - A "typing" note disappears after a few seconds without a new whisper, so a closed tab or an
      abandoned form never leaves it stuck.
--}}
@props(['household', 'members'])

@php($others = $members->reject(fn ($member) => $member->is(auth()->user()))->values())

<div
    x-data="{
        channel: 'household.{{ $household->id }}',
        me: {{ auth()->id() }},
        names: @js($others->mapWithKeys(fn ($member) => [$member->id => Str::before($member->name, ' ')])),
        online: [],
        typing: {},
        timers: {},
        presence: null,
        get label() {
            const names = this.online.map(id => this.names[id]).filter(Boolean);
            return names.length ? '{{ __('Also here:') }} ' + names.join(', ') : '';
        },
        get typingLabel() {
            const [id, editing] = Object.entries(this.typing)[0] ?? [];
            if (! id || ! this.names[id]) return '';
            return this.names[id] + ' ' + (editing ? '{{ __('is editing a bill…') }}' : '{{ __('is adding a bill…') }}');
        },
        init() {
            this.presence = window.Echo?.join(this.channel)
                .here(users => this.online = users.map(user => user.id))
                .joining(user => this.online.includes(user.id) || this.online.push(user.id))
                .leaving(user => { this.online = this.online.filter(id => id !== user.id); this.stopTyping(user.id) })
                .listenForWhisper('typing', ({ id, editing }) => this.startTyping(id, editing))
                .listenForWhisper('typing-stopped', ({ id }) => this.stopTyping(id));
        },
        startTyping(id, editing) {
            this.typing = { ...this.typing, [id]: editing };
            clearTimeout(this.timers[id]);
            this.timers[id] = setTimeout(() => this.stopTyping(id), 6000);
        },
        stopTyping(id) {
            clearTimeout(this.timers[id]);
            const { [id]: _, ...rest } = this.typing;
            this.typing = rest;
        },
        destroy() {
            Object.values(this.timers).forEach(clearTimeout);
            window.Echo?.leave(this.channel);
        },
    }"
    x-on:household-typing.window="presence?.whisper('typing', { id: me, editing: $event.detail.editing })"
    x-on:household-typing-stopped.window="presence?.whisper('typing-stopped', { id: me })"
    wire:ignore
    {{ $attributes->class('inline-flex h-6 items-center gap-1.5') }}
    data-test="household-presence"
>
    @foreach ($others as $member)
        {{-- flex (not inline) so the avatar centres on the text instead of sitting on its baseline --}}
        <span class="flex" x-show="online.includes({{ $member->id }})" x-cloak x-transition.opacity :title="names[{{ $member->id }}]">
            <x-avatar :user="$member" size="sm" />
        </span>
    @endforeach
    <span class="text-xs leading-none text-muted" x-show="label && ! typingLabel" x-text="label" x-cloak></span>
    <span class="flex items-center gap-1.5 text-xs leading-none font-semibold text-primary-text" x-show="typingLabel" x-cloak data-test="typing-indicator">
        <span class="size-1.5 animate-pulse rounded-full bg-primary-text" aria-hidden="true"></span>
        <span x-text="typingLabel"></span>
    </span>
    {{-- One polite live region for both messages, so screen readers hear who arrives and who's typing --}}
    <span class="sr-only" role="status" x-text="typingLabel || label"></span>
</div>
