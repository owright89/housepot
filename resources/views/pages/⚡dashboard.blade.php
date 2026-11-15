<?php

use App\Enums\AuditEvent;
use App\Ledger\HouseholdSummary;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
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

    #[Computed]
    public function summary(): HouseholdSummary
    {
        return HouseholdSummary::for($this->household, Auth::user());
    }

    /**
     * The five newest bills, with who paid and the splits.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function recentBills(): Collection
    {
        return $this->household->expenses()
            ->with(['payer', 'splits'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * The latest changes from the audit trail.
     *
     * @return Collection<int, Audit>
     */
    #[Computed]
    public function activity(): Collection
    {
        return $this->household->audits()
            ->with('user')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function shareOf(Expense $bill): int
    {
        return (int) $bill->splits->firstWhere('user_id', Auth::id())?->amount;
    }

    public function statusOf(Expense $bill): string
    {
        return match (true) {
            $bill->paid_by === Auth::id() => 'you-paid',
            $this->shareOf($bill) > 0 => 'you-owe',
            default => 'not-included',
        };
    }

    /**
     * "From John" / "From 2 people" style caption for a list of suggested payments.
     *
     * @param  list<array{from: User, to: User, amount: int}>  $transfers
     */
    public function peopleCaption(array $transfers, string $direction): string
    {
        if ($transfers === []) {
            return $direction === 'from' ? __('Nobody owes you') : __('You\'re all square');
        }

        if (count($transfers) > 1) {
            return $direction === 'from'
                ? __('From :count people', ['count' => count($transfers)])
                : __('To :count people', ['count' => count($transfers)]);
        }

        $person = $direction === 'from' ? $transfers[0]['from'] : $transfers[0]['to'];

        return $direction === 'from'
            ? __('From :name', ['name' => Str::before($person->name, ' ')])
            : __('To :name', ['name' => Str::before($person->name, ' ')]);
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
        @php($summary = $this->summary)
        @php($currency = $this->household->currency)
        @php($owedToYou = $summary->owedToYou(auth()->user()))
        @php($youOwe = $summary->youOwe(auth()->user()))

        {{-- Summary --}}
        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <flux:card>
                <flux:text size="sm">{{ __('You\'re owed') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-primary-text" data-test="owed-to-you">{{ Money::format(array_sum(array_column($owedToYou, 'amount')), $currency) }}</p>
                <flux:text size="sm" class="mt-1">{{ $this->peopleCaption($owedToYou, 'from') }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm">{{ __('You owe') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-coral" data-test="you-owe">{{ Money::format(array_sum(array_column($youOwe, 'amount')), $currency) }}</p>
                <flux:text size="sm" class="mt-1">{{ $this->peopleCaption($youOwe, 'to') }}</flux:text>
            </flux:card>

            <flux:card>
                <flux:text size="sm">{{ __('Your share this month') }}</flux:text>
                <p class="mt-1 text-3xl font-extrabold text-ink" data-test="your-share">{{ Money::format($summary->yourMonthShare, $currency) }}</p>
                <flux:text size="sm" class="mt-1">
                    {{ __('Of :total across :bills', ['total' => Money::format($summary->monthTotal, $currency), 'bills' => trans_choice(':count bill|:count bills', $summary->monthBillCount)]) }}
                </flux:text>
            </flux:card>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Recent bills --}}
            <flux:card class="p-0! lg:col-span-2">
                <div class="flex items-center justify-between px-5 py-4">
                    <flux:heading size="lg" level="2">{{ __('Recent bills') }}</flux:heading>
                    <flux:link :href="route('households.show', $this->household)" wire:navigate class="text-sm font-semibold">{{ __('See all') }}</flux:link>
                </div>

                @if ($this->recentBills->isEmpty())
                    <div class="border-t border-line px-5 py-10 text-center">
                        <p class="font-bold text-ink">{{ __('No bills yet') }}</p>
                        <p class="mt-1 text-sm text-muted">{{ __('Add one from the household page once someone has paid it.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-line border-t border-line" role="list">
                        @foreach ($this->recentBills as $bill)
                            <li wire:key="recent-bill-{{ $bill->id }}" class="flex items-center gap-3 px-5 py-3">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-(--radius-control) bg-subtle text-muted">
                                    <flux:icon.document-text variant="micro" aria-hidden="true" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-ink">{{ $bill->description }}</p>
                                    <p class="truncate text-xs text-muted">
                                        {{ $bill->spent_on->format('j M') }} · {{ $bill->payer->is(auth()->user()) ? __('Paid by you') : __('Paid by :name', ['name' => Str::before($bill->payer->name, ' ')]) }}
                                    </p>
                                </div>

                                <x-bill-status :status="$this->statusOf($bill)" class="hidden sm:inline-flex" />

                                <div class="text-end">
                                    <p class="text-sm font-bold text-ink">{{ Money::format($this->shareOf($bill), $currency) }}</p>
                                    <p class="text-xs text-muted">{{ __('of :total', ['total' => Money::format($bill->amount, $currency)]) }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </flux:card>

            <div class="flex flex-col gap-6">
                {{-- Balances with the viewer --}}
                <flux:card>
                    <flux:heading size="lg" level="2">{{ __('Balances') }}</flux:heading>

                    @if ($owedToYou === [] && $youOwe === [])
                        <flux:text class="mt-3">{{ __('You\'re all square with everyone.') }}</flux:text>
                    @else
                        <ul class="mt-4 space-y-3" role="list">
                            @foreach ($owedToYou as $transfer)
                                <li class="flex items-center gap-3">
                                    <x-avatar :user="$transfer['from']" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $transfer['from']->name }}</p>
                                        <p class="text-xs text-muted">{{ __('Owes you') }}</p>
                                    </div>
                                    <p class="text-sm font-bold text-primary-text">{{ Money::format($transfer['amount'], $currency) }}</p>
                                </li>
                            @endforeach
                            @foreach ($youOwe as $transfer)
                                <li class="flex items-center gap-3">
                                    <x-avatar :user="$transfer['to']" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-ink">{{ $transfer['to']->name }}</p>
                                        <p class="text-xs text-muted">{{ __('You owe') }}</p>
                                    </div>
                                    <p class="text-sm font-bold text-coral">{{ Money::format($transfer['amount'], $currency) }}</p>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Settling up arrives in roadmap step 7. --}}
                        <flux:button class="mt-4 w-full" disabled>{{ __('Settle up (coming soon)') }}</flux:button>
                    @endif
                </flux:card>

                {{-- Recent activity, from the audit trail --}}
                <flux:card>
                    <flux:heading size="lg" level="2">{{ __('Recent activity') }}</flux:heading>

                    @if ($this->activity->isEmpty())
                        <flux:text class="mt-3">{{ __('Nothing yet.') }}</flux:text>
                    @else
                        <ul class="mt-4 space-y-3" role="list">
                            @foreach ($this->activity as $audit)
                                @php($you = $audit->user->is(auth()->user()))
                                @php($who = $you ? __('You') : Str::before($audit->user->name, ' '))
                                @php($bill = $audit->changes['description'][1] ?? $audit->changes['description'][0] ?? optional($audit->subject)->description ?? __('a bill'))
                                <li class="flex items-start gap-3">
                                    <x-avatar :user="$audit->user" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">
                                            @switch($audit->event)
                                                @case(AuditEvent::Created) {{ __(':who added :bill', ['who' => $who, 'bill' => $bill]) }} @break
                                                @case(AuditEvent::Updated) {{ __(':who edited :bill', ['who' => $who, 'bill' => $bill]) }} @break
                                                @default {{ __(':who deleted :bill', ['who' => $who, 'bill' => $bill]) }}
                                            @endswitch
                                        </p>
                                        <p class="text-xs text-muted">{{ $audit->created_at->diffForHumans() }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </flux:card>
            </div>
        </div>
    @endif
</section>
