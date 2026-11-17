{{--
    Avatars of the other members who have this household open right now (Reverb presence channel).
    The avatars are rendered here for every member and shown or hidden by who's online, so names
    and colours come from the server. Purely client-side state: nothing is stored.
--}}
@props(['household', 'members'])

@php($others = $members->reject(fn ($member) => $member->is(auth()->user()))->values())

<div
    x-data="{
        channel: 'household.{{ $household->id }}',
        names: @js($others->mapWithKeys(fn ($member) => [$member->id => Str::before($member->name, ' ')])),
        online: [],
        get label() {
            const names = this.online.map(id => this.names[id]).filter(Boolean);
            return names.length ? '{{ __('Also here:') }} ' + names.join(', ') : '';
        },
        init() {
            window.Echo?.join(this.channel)
                .here(users => this.online = users.map(user => user.id))
                .joining(user => this.online.includes(user.id) || this.online.push(user.id))
                .leaving(user => this.online = this.online.filter(id => id !== user.id));
        },
        destroy() {
            window.Echo?.leave(this.channel);
        },
    }"
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
    <span class="text-xs leading-none text-muted" x-show="label" x-text="label" x-cloak role="status"></span>
</div>
