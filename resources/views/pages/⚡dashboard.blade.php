<?php

use App\Models\Household;
use App\Models\User;
use App\Support\DemoData;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function household(): ?Household
    {
        return Auth::user()->currentHousehold();
    }

    public function greeting(): string
    {
        $part = match (true) {
            now()->hour < 12 => __('Good morning'),
            now()->hour < 18 => __('Good afternoon'),
            default => __('Good evening'),
        };

        return $part.', '.Str::before(Auth::user()->name, ' ');
    }

    /**
     * Placeholder figures until the ledger exists (step 5).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function demo(): array
    {
        $members = $this->household->members()->orderBy('name')->get();
        $bills = DemoData::bills($members);
        $balances = DemoData::balances($members, Auth::user());

        return [
            'bills' => $bills,
            'balances' => $balances,
            'activity' => DemoData::activity($bills),
            'owed' => collect($balances)->where('amount', '>', 0)->first(),
            'owe' => collect($balances)->where('amount', '<', 0)->first(),
            'monthTotal' => DemoData::monthTotal($bills),
            'yourShare' => DemoData::yourShare($bills),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1" class="text-2xl! font-extrabold!">{{ $this->greeting() }}</flux:heading>
            @if ($this->household)
                <flux:text class="mt-1">{{ __('Here\'s where :household stands this month.', ['household' => $this->household->name]) }}</flux:text>
            @endif
        </div>

        @if ($this->household)
            <flux:button variant="primary" icon="plus" :href="route('households.show', $this->household)" wire:navigate>
                {{ __('Add bill') }}
            </flux:button>
        @endif
    </div>

    @if (! $this->household)
        <flux:card class="mx-auto flex max-w-lg flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-(--radius-control) bg-primary-soft p-3">
                <flux:icon.home-modern class="size-6 text-primary-text" aria-hidden="true" />
            </div>
            <div class="space-y-1">
                <flux:heading size="lg" level="2">{{ __('Set up your household') }}</flux:heading>
                <flux:text>{{ __('Create a household for your flat, then invite your flatmates to start splitting bills.') }}</flux:text>
            </div>
            <flux:button variant="primary" :href="route('households.index')" wire:navigate>
                {{ __('Go to households') }}
            </flux:button>
        </flux:card>
    @else
        @php($demo = $this->demo)
        @php($currency = $this->household->currency)

        {{-- Summary --}}
        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text size="sm">{{ __('You\'re owed') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-primary-text">{{ Money::format($demo['owed']['amount'] ?? 0, $currency) }}</p>
                <flux:text size="sm" class="mt-1">
                    {{ $demo['owed'] ? __('From :name', ['name' => Str::before($demo['owed']['user']->name, ' ')]) : __('Nobody owes you') }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm">{{ __('You owe') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-coral">{{ Money::format(abs($demo['owe']['amount'] ?? 0), $currency) }}</p>
                <flux:text size="sm" class="mt-1">
                    {{ $demo['owe'] ? __('To :name', ['name' => Str::before($demo['owe']['user']->name, ' ')]) : __('You\'re all square') }}
                </flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm">{{ __('Your share this month') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-ink">{{ Money::format($demo['yourShare'], $currency) }}</p>
                <flux:text size="sm" class="mt-1">
                    {{ __('Of :total across :count bills', ['total' => Money::format($demo['monthTotal'], $currency), 'count' => count($demo['bills'])]) }}
                </flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Upcoming bills --}}
            <flux:card class="p-0! lg:col-span-2">
                <div class="flex items-center justify-between px-5 py-4">
                    <flux:heading size="lg" level="2">{{ __('Upcoming bills') }}</flux:heading>
                    <flux:link :href="route('households.show', $this->household)" wire:navigate class="text-sm font-semibold">{{ __('See all') }}</flux:link>
                </div>

                <ul class="divide-y divide-line border-t border-line" role="list">
                    @foreach ($demo['bills'] as $bill)
                        <li class="flex items-center gap-3 px-5 py-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-(--radius-control) bg-subtle text-muted">
                                <flux:icon.document-text variant="micro" aria-hidden="true" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-ink">{{ $bill['name'] }}</p>
                                <p class="truncate text-xs text-muted">
                                    {{ $bill['due'] }} · {{ $bill['payer']->is(auth()->user()) ? __('Paid by you') : __('Paid by :name', ['name' => Str::before($bill['payer']->name, ' ')]) }}
                                </p>
                            </div>

                            <x-bill-status :status="$bill['status']" class="hidden sm:inline-flex" />

                            <div class="text-end">
                                <p class="text-sm font-bold text-ink">{{ Money::format($bill['share'], $currency) }}</p>
                                <p class="text-xs text-muted">{{ __('of :total', ['total' => Money::format($bill['total'], $currency)]) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </flux:card>

            <div class="flex flex-col gap-6">
                {{-- Balances --}}
                <flux:card>
                    <flux:heading size="lg" level="2">{{ __('Balances') }}</flux:heading>

                    @if (empty($demo['balances']))
                        <flux:text class="mt-3">{{ __('Invite your housemates to start sharing bills.') }}</flux:text>
                    @else
                        <ul class="mt-4 space-y-3" role="list">
                            @foreach ($demo['balances'] as $balance)
                                <li class="flex items-center gap-3">
                                    <x-avatar :user="$balance['user']" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $balance['user']->name }}</p>
                                        <p class="text-xs text-muted">{{ $balance['amount'] > 0 ? __('Owes you') : __('You owe') }}</p>
                                    </div>
                                    <p @class(['text-sm font-bold', 'text-primary-text' => $balance['amount'] > 0, 'text-coral' => $balance['amount'] < 0])>
                                        {{ Money::format(abs($balance['amount']), $currency) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>

                        <flux:button class="mt-4 w-full">{{ __('Settle up') }}</flux:button>
                    @endif
                </flux:card>

                {{-- Recent activity --}}
                <flux:card>
                    <flux:heading size="lg" level="2">{{ __('Recent activity') }}</flux:heading>

                    <ul class="mt-4 space-y-3" role="list">
                        @foreach ($demo['activity'] as $item)
                            <li class="flex items-start gap-3">
                                <x-avatar :user="$item['user']" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-ink">
                                        {{ $item['user']->is(auth()->user()) ? __('You added :bill', ['bill' => $item['text']]) : __(':name added :bill', ['name' => Str::before($item['user']->name, ' '), 'bill' => $item['text']]) }}
                                    </p>
                                    <p class="text-xs text-muted">{{ $item['detail'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </flux:card>
            </div>
        </div>
    @endif
</section>
