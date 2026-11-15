{{--
    Status chip for a bill, from the viewer's point of view:
    you-paid (primary), you-owe (coral), not-included (neutral).
    The landing page's sample card also uses due / upcoming / paid.
--}}
@props(['status'])

@switch($status)
    @case('you-paid')
        <x-chip variant="primary">{{ __('You paid') }}</x-chip>
        @break
    @case('you-owe')
        <x-chip variant="coral">{{ __('Your share') }}</x-chip>
        @break
    @case('not-included')
        <x-chip>{{ __('Not included') }}</x-chip>
        @break
    @case('due')
        <x-chip variant="coral">{{ __('Due soon') }}</x-chip>
        @break
    @case('paid')
        <x-chip variant="primary">{{ __('Paid') }}</x-chip>
        @break
    @default
        <x-chip>{{ __('Upcoming') }}</x-chip>
@endswitch
