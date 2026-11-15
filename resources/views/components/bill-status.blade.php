{{-- Status chip for a bill: due (coral), upcoming (neutral) or paid (primary). --}}
@props(['status'])

@switch($status)
    @case('due')
        <x-chip variant="coral">{{ __('Due soon') }}</x-chip>
        @break
    @case('paid')
        <x-chip variant="primary">{{ __('Paid') }}</x-chip>
        @break
    @default
        <x-chip>{{ __('Upcoming') }}</x-chip>
@endswitch
