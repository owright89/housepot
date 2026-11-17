<?php

use App\Enums\AuditEvent;
use App\Ledger\HouseholdSummary;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;
use App\Reports\MonthlySpending;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** Spending chart: 'household' (all bills) or 'mine' (the viewer's shares). */
    public string $spendingView = 'household';

    /** Spending chart: the month shown by category ('Y-m'); null means the latest. */
    public ?string $spendingMonth = null;

    #[Computed]
    public function household(): ?Household
    {
        return Auth::user()->currentHousehold();
    }

    /**
     * Live updates for the current household. Built in code because a new user has no household.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $user = 'echo-private:App.Models.User.'.Auth::id();

        $listeners = [
            'echo-reconnected' => 'refreshFigures',
            "{$user},.settlement.requested" => 'paymentRequested',
            "{$user},.settlement.rejected" => 'paymentRejected',
        ];

        if ($this->household !== null) {
            $channel = "echo-private:households.{$this->household->id}";

            $listeners["{$channel},.balances.updated"] = 'refreshFigures';
            $listeners["{$channel},.expense.recorded"] = 'billAdded';
            $listeners["{$channel},.expense.updated"] = 'billEdited';
            $listeners["{$channel},.expense.deleted"] = 'billDeleted';
            $listeners["{$channel},.settlement.confirmed"] = 'paymentConfirmed';
        }

        return $listeners;
    }

    /**
     * Re-read figures, bills and activity from the database (the socket is only a nudge).
     */
    public function refreshFigures(): void
    {
        unset($this->summary, $this->recentBills, $this->activity, $this->spending);
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    public function billAdded(array $event): void
    {
        $this->announce($event, __(':name added :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    public function billEdited(array $event): void
    {
        $this->announce($event, __(':name edited :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    public function billDeleted(array $event): void
    {
        $this->announce($event, __(':name deleted :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * @param  array{actor_name: string, amount: string}  $event
     */
    public function paymentRequested(array $event): void
    {
        Flux::toast(text: __(':name says they paid you :amount. Please confirm it.', ['name' => $event['actor_name'], 'amount' => $event['amount']]));
    }

    /**
     * @param  array{actor_name: string, amount: string}  $event
     */
    public function paymentRejected(array $event): void
    {
        unset($this->activity);

        Flux::toast(variant: 'warning', text: __(':name didn\'t confirm your :amount payment.', ['name' => $event['actor_name'], 'amount' => $event['amount']]));
    }

    /**
     * @param  array{actor_id: int, actor_name: string, from_name: string, amount: string}  $event
     */
    public function paymentConfirmed(array $event): void
    {
        unset($this->activity);

        if ($event['actor_id'] !== Auth::id()) {
            Flux::toast(text: __(':name confirmed :from\'s :amount payment.', ['name' => $event['actor_name'], 'from' => $event['from_name'], 'amount' => $event['amount']]));
        }
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    private function announce(array $event, string $message): void
    {
        unset($this->recentBills, $this->activity, $this->spending);

        if ($event['actor_id'] !== Auth::id()) {
            Flux::toast(text: $message);
        }
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
     * The last six months of spending: the household's bills, or just the viewer's shares.
     */
    #[Computed]
    public function spending(): MonthlySpending
    {
        return MonthlySpending::for($this->household, $this->spendingView === 'mine' ? Auth::user() : null);
    }

    public function updatedSpendingView(string $value): void
    {
        // Anything other than the two known views falls back to the household total.
        $this->spendingView = $value === 'mine' ? 'mine' : 'household';
        unset($this->spending);
    }

    public function selectSpendingMonth(string $key): void
    {
        // Unknown months fall back to the latest (MonthlySpending::month()).
        $this->spendingMonth = $key;
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
            ->with(['user', 'subject' => fn (MorphTo $subject) => $subject->morphWith([
                Settlement::class => ['fromUser', 'toUser'],
            ])])
            ->latest('id')
            ->limit(5)
            ->get();
    }

    /**
     * One line of the activity feed, e.g. "You added Energy" or "Alice confirmed Bob's £15 payment".
     */
    public function describe(Audit $audit): string
    {
        $name = fn (?User $user) => $user?->is(Auth::user()) ? __('you') : Str::before($user?->name ?? __('someone'), ' ');
        $who = Str::ucfirst($name($audit->user));

        if ($audit->subject_type === 'settlement') {
            /** @var Settlement|null $payment */
            $payment = $audit->subject;
            $amount = $payment ? Money::format($payment->amount, $this->household->currency) : '';

            return match ($audit->event) {
                AuditEvent::Created => __(':who recorded paying :to :amount', ['who' => $who, 'to' => $name($payment?->toUser), 'amount' => $amount]),
                AuditEvent::Confirmed => __(':who confirmed :from\'s :amount payment', ['who' => $who, 'from' => $name($payment?->fromUser), 'amount' => $amount]),
                AuditEvent::Rejected => __(':who didn\'t confirm :from\'s :amount payment', ['who' => $who, 'from' => $name($payment?->fromUser), 'amount' => $amount]),
                default => __(':who changed a payment', ['who' => $who]),
            };
        }

        $bill = $audit->changes['description'][1] ?? $audit->changes['description'][0] ?? $audit->subject?->description ?? __('a bill');

        return match ($audit->event) {
            AuditEvent::Created => __(':who added :bill', ['who' => $who, 'bill' => $bill]),
            AuditEvent::Updated => __(':who edited :bill', ['who' => $who, 'bill' => $bill]),
            default => __(':who deleted :bill', ['who' => $who, 'bill' => $bill]),
        };
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
                <flux:text class="mt-1 flex flex-wrap items-center gap-2">
                    <span>{{ __('Here\'s where :household stands this month.', ['household' => $this->household->name]) }}</span>
                    <x-live-indicator />
                </flux:text>
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
        <x-stat-group class="mb-6">
            <x-stat :label="__('You\'re owed')" :caption="$this->peopleCaption($owedToYou, 'from')" class="text-primary-text" data-test="owed-to-you">
                {{ Money::format(array_sum(array_column($owedToYou, 'amount')), $currency) }}
            </x-stat>

            <x-stat :label="__('You owe')" :caption="$this->peopleCaption($youOwe, 'to')" class="text-coral" data-test="you-owe">
                {{ Money::format(array_sum(array_column($youOwe, 'amount')), $currency) }}
            </x-stat>

            <x-stat
                :label="__('Your share this month')"
                :caption="__('Of :total across :bills', ['total' => Money::format($summary->monthTotal, $currency), 'bills' => trans_choice(':count bill|:count bills', $summary->monthBillCount)])"
                class="text-ink"
                data-test="your-share"
            >
                {{ Money::format($summary->yourMonthShare, $currency) }}
            </x-stat>
        </x-stat-group>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
            {{-- Recent bills --}}
            <flux:card class="p-0!">
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
                                {{-- Category icon; the label is in the caption below, so meaning never relies on the icon --}}
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-(--radius-control) bg-subtle text-muted">
                                    <flux:icon :icon="$bill->displayCategory()->icon()" variant="micro" aria-hidden="true" />
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold text-ink">{{ $bill->description }}</p>
                                    <p class="truncate text-xs text-muted">
                                        {{ $bill->displayCategory()->label() }} · {{ $bill->spent_on->format('j M') }} · {{ $bill->payer->is(auth()->user()) ? __('Paid by you') : __('Paid by :name', ['name' => Str::before($bill->payer->name, ' ')]) }}
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

            {{-- Spending over the last six months --}}
            <flux:card data-test="spending-card">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Spending') }}</flux:heading>
                        <flux:text size="sm" class="mt-0.5">
                            {{ $spendingView === 'mine' ? __('Your share of bills, last 6 months') : __('All household bills, last 6 months') }}
                        </flux:text>
                    </div>

                    <div class="flex rounded-(--radius-control) bg-subtle p-1" role="group" aria-label="{{ __('Whose spending') }}">
                        @foreach (['household' => __('Household'), 'mine' => __('Your share')] as $value => $label)
                            <button
                                type="button"
                                wire:click="$set('spendingView', '{{ $value }}')"
                                aria-pressed="{{ $spendingView === $value ? 'true' : 'false' }}"
                                @class([
                                    'rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors',
                                    'bg-card text-ink shadow-xs' => $spendingView === $value,
                                    'text-muted hover:text-ink' => $spendingView !== $value,
                                ])
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Refetch keeps the frame: dim, don't blank --}}
                <div wire:loading.class="opacity-60" wire:target="spendingView, selectSpendingMonth" class="transition-opacity">
                    <x-spending-chart :spending="$this->spending" :selected="$spendingMonth" :currency="$currency" />
                </div>
            </flux:card>
            </div>

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

                        <flux:button class="mt-4 w-full" :href="route('households.show', [$this->household, 'payments'])" wire:navigate data-test="dashboard-settle-up">{{ __('Settle up') }}</flux:button>
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
                                <li class="flex items-start gap-3">
                                    <x-avatar :user="$audit->user" />
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink">{{ $this->describe($audit) }}</p>
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
