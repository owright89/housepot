{{--
    Rounded-square initials avatar. The colour is picked from the user's id, so each person keeps
    the same colour everywhere. Decorative: the name is always shown or announced next to it.
--}}
@props(['user', 'size' => 'md'])

@php
    $palette = [
        'bg-primary-soft text-primary-text',
        'bg-coral-soft text-coral',
        'bg-[#E6ECFB] text-[#3556A8] dark:bg-[#1E2A44] dark:text-[#9DB6F5]',
        'bg-[#FBEFD9] text-[#8A5300] dark:bg-[#3A2E14] dark:text-[#EDBE6A]',
        'bg-[#EFE8FA] text-[#6A44A8] dark:bg-[#2D2440] dark:text-[#C4ADF2]',
    ];
    $colour = $palette[$user->id % count($palette)];
@endphp

<span aria-hidden="true" {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-(--radius-control) font-bold',
    'size-6 text-[10px]' => $size === 'sm',
    'size-8 text-xs' => $size === 'md',
    $colour,
]) }}>{{ $user->initials() }}</span>
