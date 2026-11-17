<?php

use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Expenses\UpdateExpense;
use App\Actions\Households\CreateInvite;
use App\Actions\Settlements\ConfirmSettlement;
use App\Actions\Settlements\RejectSettlement;
use App\Actions\Settlements\RequestSettlement;
use App\Enums\HouseholdRole;
use App\Enums\SettlementStatus;
use App\Ledger\HouseholdSummary;
use App\Ledger\InvalidExpense;
use App\Ledger\InvalidSettlement;
use App\Ledger\StaleExpense;
use App\Livewire\Forms\ExpenseForm;
use App\Models\Expense;
use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public Household $household;

    /**
     * Bills table filter: all, you-paid or you-owe.
     */
    public string $filter = 'all';

    /**
     * The link from the most recent invite, shown in the invite modal.
     */
    public ?string $inviteUrl = null;

    public ExpenseForm $form;

    /** The bill waiting for delete confirmation, and the version that was shown. */
    public ?int $deletingId = null;

    public int $deletingVersion = 0;

    /** Who's viewing: used to listen on their private channel for payments to confirm. */
    #[Locked]
    public int $viewerId;

    /** Settle up form: who you paid and how much (as typed). */
    public ?int $payTo = null;

    public string $payAmount = '';

    public function mount(): void
    {
        $this->viewerId = Auth::id();

        // Remember this household so the sidebar switcher and dashboard open it next time.
        session(['current_household_id' => $this->household->id]);
    }

    /**
     * The current members of the household, sorted by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->household
            ->members()
            ->orderBy('name')
            ->get();
    }

    /**
     * Newest bills first, with who paid and the splits.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function bills(): Collection
    {
        return $this->household->expenses()
            ->with(['payer', 'splits'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function summary(): HouseholdSummary
    {
        return HouseholdSummary::for($this->household, Auth::user());
    }

    /**
     * Payments waiting for an answer that involve the viewer: ones to confirm, and ones they sent.
     *
     * @return Collection<int, Settlement>
     */
    #[Computed]
    public function pendingPayments(): Collection
    {
        return $this->household->settlements()
            ->with(['fromUser', 'toUser'])
            ->where('status', SettlementStatus::Pending)
            ->where(fn ($query) => $query->where('to_user_id', Auth::id())->orWhere('from_user_id', Auth::id()))
            ->latest('id')
            ->get();
    }

    /**
     * The viewer's share of a bill, in pence.
     */
    public function shareOf(Expense $bill): int
    {
        return (int) $bill->splits->firstWhere('user_id', Auth::id())?->amount;
    }

    /**
     * you-paid | you-owe | not-included, from the viewer's point of view.
     */
    public function statusOf(Expense $bill): string
    {
        return match (true) {
            $bill->paid_by === Auth::id() => 'you-paid',
            $this->shareOf($bill) > 0 => 'you-owe',
            default => 'not-included',
        };
    }

    /**
     * @return Collection<int, Expense>
     */
    public function filteredBills(): Collection
    {
        if ($this->filter === 'all') {
            return $this->bills;
        }

        return $this->bills->filter(fn (Expense $bill) => $this->statusOf($bill) === $this->filter)->values();
    }

    public function addBill(): void
    {
        $this->authorize('create', [Expense::class, $this->household]);

        $this->form->start($this->household, Auth::user());

        Flux::modal('bill-form')->show();
    }

    public function editBill(int $expenseId): void
    {
        $expense = $this->household->expenses()->findOrFail($expenseId);
        $this->authorize('update', $expense);

        $this->form->edit($expense);

        Flux::modal('bill-form')->show();
    }

    public function saveBill(RecordExpense $recordExpense, UpdateExpense $updateExpense): void
    {
        $data = $this->form->toData($this->household);

        try {
            if ($this->form->expenseId === null) {
                $this->authorize('create', [Expense::class, $this->household]);
                $recordExpense->handle(Auth::user(), $this->household, $data);
                $message = __('Bill added.');
            } else {
                $expense = $this->household->expenses()->findOrFail($this->form->expenseId);
                $this->authorize('update', $expense);
                $updateExpense->handle(Auth::user(), $expense, $this->form->version, $data);
                $message = __('Bill updated.');
            }
        } catch (InvalidExpense $e) {
            $this->addError('form.shares', $e->getMessage());

            return;
        } catch (StaleExpense $e) {
            Flux::modal('bill-form')->close();
            Flux::toast(variant: 'danger', text: $e->getMessage());
            $this->refreshFigures();

            return;
        }

        Flux::modal('bill-form')->close();
        Flux::toast(variant: 'success', text: $message);
        $this->refreshFigures();
    }

    public function confirmDelete(int $expenseId): void
    {
        $expense = $this->household->expenses()->findOrFail($expenseId);
        $this->authorize('delete', $expense);

        $this->deletingId = $expense->id;
        $this->deletingVersion = $expense->version;

        Flux::modal('delete-bill')->show();
    }

    public function deleteBill(DeleteExpense $deleteExpense): void
    {
        $expense = $this->household->expenses()->findOrFail($this->deletingId);
        $this->authorize('delete', $expense);

        try {
            $deleteExpense->handle(Auth::user(), $expense, $this->deletingVersion);
            Flux::toast(variant: 'success', text: __('Bill deleted.'));
        } catch (InvalidExpense|StaleExpense $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        Flux::modal('delete-bill')->close();
        $this->deletingId = null;
        $this->refreshFigures();
    }

    /**
     * Open the settle up form. Prefilled with the suggested payment to $to, or the viewer's
     * biggest suggested payment when no one is given.
     */
    public function settleUp(?int $to = null): void
    {
        $this->authorize('create', [Settlement::class, $this->household]);

        $suggestions = $this->summary->youOwe(Auth::user());
        $suggestion = collect($suggestions)->first(fn (array $t) => $to === null || $t['to']->id === $to);

        $this->resetErrorBag();
        $this->payTo = $to ?? $suggestion['to']->id ?? null;
        $this->payAmount = $suggestion ? Money::toInput($suggestion['amount']) : '';

        Flux::modal('settle-up')->show();
    }

    public function recordPayment(RequestSettlement $requestSettlement): void
    {
        $this->authorize('create', [Settlement::class, $this->household]);

        $to = $this->payTo === null ? null : $this->household->members()->find($this->payTo);

        if ($to === null) {
            $this->addError('payTo', __('Choose who you paid.'));

            return;
        }

        $amount = Money::parse($this->payAmount);

        if ($amount === null) {
            $this->addError('payAmount', __('Enter an amount like 12.50.'));

            return;
        }

        try {
            $requestSettlement->handle(Auth::user(), $this->household, $to, $amount);
        } catch (InvalidSettlement $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        Flux::modal('settle-up')->close();
        Flux::toast(variant: 'success', text: __(':name will be asked to confirm it arrived.', ['name' => Str::before($to->name, ' ')]));
        unset($this->pendingPayments);
    }

    public function confirmPayment(int $settlementId, ConfirmSettlement $confirmSettlement): void
    {
        $settlement = $this->household->settlements()->findOrFail($settlementId);
        $this->authorize('respond', $settlement);

        try {
            $confirmSettlement->handle(Auth::user(), $settlement);
            Flux::toast(variant: 'success', text: __('Payment confirmed. Balances updated.'));
        } catch (InvalidSettlement $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->refreshFigures();
    }

    public function rejectPayment(int $settlementId, RejectSettlement $rejectSettlement): void
    {
        $settlement = $this->household->settlements()->findOrFail($settlementId);
        $this->authorize('respond', $settlement);

        try {
            $rejectSettlement->handle(Auth::user(), $settlement);
            Flux::toast(text: __('Payment rejected. Balances are unchanged.'));
        } catch (InvalidSettlement $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        $this->refreshFigures();
    }

    /**
     * Show the household's live invite link, creating one only if none exists.
     * Opening the modal repeatedly reuses the same link, so it never piles up rows.
     */
    public function openInvite(CreateInvite $createInvite): void
    {
        $this->authorize('invite', $this->household);

        $invite = $this->household->activeInvite() ?? $createInvite->handle(Auth::user(), $this->household);
        $this->inviteUrl = $invite->url();

        Flux::modal('invite-housemate')->show();
    }

    /**
     * Replace the live link with a new one. The old link stops working (it is revoked).
     */
    public function newInviteLink(CreateInvite $createInvite): void
    {
        $this->authorize('invite', $this->household);

        $this->inviteUrl = $createInvite->handle(Auth::user(), $this->household)->url();

        Flux::toast(text: __('New link created. The old one no longer works.'));
    }

    /**
     * Re-read bills and figures from the database. Called after saving, when another member's
     * change is broadcast (Reverb), and when the live connection comes back after dropping.
     */
    #[On('echo-private:households.{household.id},.balances.updated')]
    #[On('echo-reconnected')]
    public function refreshFigures(): void
    {
        unset($this->bills, $this->summary, $this->pendingPayments);
    }

    /**
     * Someone says they paid the viewer (private user channel, so it can be any household).
     *
     * @param  array{household_id: int, actor_name: string, amount: string}  $event
     */
    #[On('echo-private:App.Models.User.{viewerId},.settlement.requested')]
    public function paymentRequested(array $event): void
    {
        unset($this->pendingPayments);

        Flux::toast(text: __(':name says they paid you :amount. Please confirm it.', ['name' => $event['actor_name'], 'amount' => $event['amount']]));
    }

    /**
     * The recipient said the viewer's payment never arrived.
     *
     * @param  array{household_id: int, actor_name: string, amount: string}  $event
     */
    #[On('echo-private:App.Models.User.{viewerId},.settlement.rejected')]
    public function paymentRejected(array $event): void
    {
        unset($this->pendingPayments);

        Flux::toast(variant: 'warning', text: __(':name didn\'t confirm your :amount payment.', ['name' => $event['actor_name'], 'amount' => $event['amount']]));
    }

    /**
     * A payment was confirmed. Balances arrive separately (.balances.updated).
     *
     * @param  array{actor_id: int, actor_name: string, from_name: string, amount: string}  $event
     */
    #[On('echo-private:households.{household.id},.settlement.confirmed')]
    public function paymentConfirmed(array $event): void
    {
        unset($this->pendingPayments);

        if ($event['actor_id'] !== Auth::id()) {
            Flux::toast(text: __(':name confirmed :from\'s :amount payment.', ['name' => $event['actor_name'], 'from' => $event['from_name'], 'amount' => $event['amount']]));
        }
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    #[On('echo-private:households.{household.id},.expense.recorded')]
    public function billAdded(array $event): void
    {
        $this->announce($event, __(':name added :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    #[On('echo-private:households.{household.id},.expense.updated')]
    public function billEdited(array $event): void
    {
        $this->announce($event, __(':name edited :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    #[On('echo-private:households.{household.id},.expense.deleted')]
    public function billDeleted(array $event): void
    {
        $this->announce($event, __(':name deleted :bill', ['name' => $event['actor_name'], 'bill' => $event['description']]));
    }

    /**
     * Refresh the bills, and tell the user what changed unless they made the change themselves.
     *
     * @param  array{actor_id: int, actor_name: string, description: string}  $event
     */
    private function announce(array $event, string $message): void
    {
        unset($this->bills);

        if ($event['actor_id'] !== Auth::id()) {
            Flux::toast(text: $message);
        }
    }

    public function render()
    {
        return $this->view()->title($this->household->name);
    }
};
?>

@php($currency = $household->currency)
@php($summary = $this->summary)
@php($isOwner = $household->roleOf(auth()->user()) === HouseholdRole::Owner)

<section class="w-full">
    <flux:breadcrumbs class="mb-3">
        <flux:breadcrumbs.item :href="route('households.index')" wire:navigate>{{ __('Households') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $household->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <flux:heading size="xl" level="1" class="break-words text-2xl! font-extrabold!">{{ $household->name }}</flux:heading>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted">
                <span>{{ $household->currency }} · {{ trans_choice(':count member|:count members', $this->members->count()) }}@if ($isOwner) · {{ __('You\'re the owner') }}@endif</span>
                <x-live-indicator />
                <x-household-presence :household="$household" :members="$this->members" />
            </p>
        </div>

        <div class="flex gap-2">
            @can('invite', $household)
                <flux:button icon="user-plus" wire:click="openInvite" data-test="invite-button">{{ __('Invite') }}</flux:button>
            @endcan
            @can('create', [Expense::class, $household])
                <flux:button variant="primary" icon="plus" wire:click="addBill" data-test="add-bill-button">{{ __('Add bill') }}</flux:button>
            @endcan
        </div>
    </div>

    @if (session('invite_status'))
        <flux:callout variant="success" icon="check-circle" class="mb-6" data-test="invite-status">
            <flux:callout.heading>{{ session('invite_status') }}</flux:callout.heading>
            <flux:callout.text>{{ __('You can now see and add the household\'s bills.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($household->isArchived())
        <flux:callout variant="secondary" icon="archive-box" class="mb-6">
            <flux:callout.heading>{{ __('This household is archived') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Archived on :date. You can still see its members and history, but nothing can be changed.', ['date' => $household->archived_at->toFormattedDateString()]) }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text size="sm">{{ __('Total this month') }}</flux:text>
            <p class="mt-1 text-2xl font-extrabold text-ink" data-test="month-total">{{ Money::format($summary->monthTotal, $currency) }}</p>
            <flux:text size="sm" class="mt-1">{{ trans_choice(':count bill|:count bills', $summary->monthBillCount) }}</flux:text>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Your share this month') }}</flux:text>
            <p class="mt-1 text-2xl font-extrabold text-ink" data-test="your-share">{{ Money::format($summary->yourMonthShare, $currency) }}</p>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Your balance') }}</flux:text>
            <p @class(['mt-1 text-2xl font-extrabold', 'text-primary-text' => $summary->yourBalance > 0, 'text-coral' => $summary->yourBalance < 0, 'text-ink' => $summary->yourBalance === 0]) data-test="your-balance">
                {{ Money::signed($summary->yourBalance, $currency) }}
            </p>
            <flux:text size="sm" class="mt-1">
                {{ $summary->yourBalance > 0 ? __('You\'re owed overall') : ($summary->yourBalance < 0 ? __('You owe overall') : __('All square')) }}
            </flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Bills --}}
        <flux:card class="p-0! lg:col-span-2" id="bills">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <flux:heading size="lg" level="2">{{ __('Bills') }}</flux:heading>

                <div class="flex rounded-(--radius-control) bg-subtle p-1" role="group" aria-label="{{ __('Filter bills') }}">
                    @foreach (['all' => __('All'), 'you-paid' => __('You paid'), 'you-owe' => __('Your share')] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $value }}')"
                            aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                            @class([
                                'rounded-lg px-3 py-1.5 text-sm font-semibold transition-colors',
                                'bg-card text-ink shadow-xs' => $filter === $value,
                                'text-muted hover:text-ink' => $filter !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->bills->isEmpty())
                <div class="flex flex-col items-center gap-3 border-t border-line px-5 py-12 text-center">
                    <span class="flex size-10 items-center justify-center rounded-(--radius-control) bg-subtle text-muted" aria-hidden="true">
                        <flux:icon.document-text variant="mini" />
                    </span>
                    <p class="font-bold text-ink">{{ __('No bills yet') }}</p>
                    <p class="max-w-xs text-sm text-muted">{{ __('Add a bill once someone has paid it, and HousePot works out everyone\'s share.') }}</p>
                    @can('create', [Expense::class, $household])
                        <flux:button variant="primary" icon="plus" wire:click="addBill">{{ __('Add the first bill') }}</flux:button>
                    @endcan
                </div>
            @else
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-xs text-muted">
                                <th scope="col" class="px-5 py-2 text-start font-medium">{{ __('Bill') }}</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Paid by') }}</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Date') }}</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Amount') }}</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Your share') }}</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Status') }}</th>
                                <th scope="col" class="w-10 px-3 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line border-t border-line">
                            @forelse ($this->filteredBills() as $bill)
                                <tr wire:key="bill-{{ $bill->id }}">
                                    <td class="px-5 py-3 font-bold text-ink">{{ $bill->description }}</td>
                                    <td class="px-3 py-3">
                                        <span class="flex items-center gap-2 text-muted">
                                            <x-avatar :user="$bill->payer" size="sm" />
                                            {{ $bill->payer->is(auth()->user()) ? __('You') : Str::before($bill->payer->name, ' ') }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap text-muted">{{ $bill->spent_on->format('j M') }}</td>
                                    <td class="px-3 py-3 text-end text-muted">{{ Money::format($bill->amount, $currency) }}</td>
                                    <td class="px-3 py-3 text-end font-bold text-ink">{{ Money::format($this->shareOf($bill), $currency) }}</td>
                                    <td class="px-3 py-3 text-end"><x-bill-status :status="$this->statusOf($bill)" /></td>
                                    <td class="px-3 py-3 text-end">
                                        @can('update', $bill)
                                            <flux:dropdown align="end">
                                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" :aria-label="__('Actions for :bill', ['bill' => $bill->description])" />
                                                <flux:menu>
                                                    <flux:menu.item icon="pencil-square" wire:click="editBill({{ $bill->id }})">{{ __('Edit') }}</flux:menu.item>
                                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $bill->id }})">{{ __('Delete') }}</flux:menu.item>
                                                </flux:menu>
                                            </flux:dropdown>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-muted">{{ __('No bills here.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </flux:card>

        <div class="flex flex-col gap-6">
            {{-- Payments waiting for an answer: only the recipient can confirm --}}
            @if ($this->pendingPayments->isNotEmpty())
                <flux:card data-test="pending-payments">
                    <flux:heading size="lg" level="2">{{ __('Payments to confirm') }}</flux:heading>

                    <ul class="mt-3 divide-y divide-line" role="list">
                        @foreach ($this->pendingPayments as $payment)
                            @php($toYou = $payment->to_user_id === auth()->id())
                            <li wire:key="payment-{{ $payment->id }}" class="py-3">
                                <div class="flex items-center gap-3">
                                    <x-avatar :user="$toYou ? $payment->fromUser : $payment->toUser" size="sm" />
                                    <p class="min-w-0 flex-1 text-sm text-muted">
                                        @if ($toYou)
                                            {{ __(':name says they paid you', ['name' => Str::before($payment->fromUser->name, ' ')]) }}
                                        @else
                                            {{ __('You paid :name · waiting for them to confirm', ['name' => Str::before($payment->toUser->name, ' ')]) }}
                                        @endif
                                    </p>
                                    <span class="text-sm font-bold text-ink">{{ Money::format($payment->amount, $currency) }}</span>
                                </div>

                                @can('respond', $payment)
                                    <div class="mt-3 grid grid-cols-2 gap-2">
                                        <flux:button size="sm" wire:click="rejectPayment({{ $payment->id }})" data-test="reject-payment">{{ __('Not received') }}</flux:button>
                                        <flux:button size="sm" variant="primary" wire:click="confirmPayment({{ $payment->id }})" data-test="confirm-payment">{{ __('Confirm') }}</flux:button>
                                    </div>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </flux:card>
            @endif

            {{-- Who owes who: suggested payments from the ledger --}}
            <flux:card>
                <flux:heading size="lg" level="2">{{ __('Who owes who') }}</flux:heading>

                @if (empty($summary->transfers))
                    <flux:text class="mt-3">{{ __('Everyone\'s square. Nothing to settle.') }}</flux:text>
                @else
                    <ul class="mt-4 space-y-3 text-sm" role="list" data-test="transfers">
                        @foreach ($summary->transfers as $transfer)
                            @php($toYou = $transfer['to']->is(auth()->user()))
                            @php($fromYou = $transfer['from']->is(auth()->user()))
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-muted">
                                    @if ($toYou)
                                        {{ Str::before($transfer['from']->name, ' ') }} {{ __('owes') }} <strong class="text-ink">{{ __('you') }}</strong>
                                    @elseif ($fromYou)
                                        <strong class="text-ink">{{ __('You') }}</strong> {{ __('owe') }} {{ Str::before($transfer['to']->name, ' ') }}
                                    @else
                                        {{ Str::before($transfer['from']->name, ' ') }} {{ __('owes') }} {{ Str::before($transfer['to']->name, ' ') }}
                                    @endif
                                </span>
                                <span @class(['font-bold', 'text-primary-text' => $toYou, 'text-coral' => $fromYou, 'text-muted' => ! $toYou && ! $fromYou])>
                                    {{ Money::format($transfer['amount'], $currency) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @can('create', [Settlement::class, $household])
                        <flux:button variant="primary" class="mt-4 w-full" wire:click="settleUp" data-test="settle-up-button">{{ __('Settle up') }}</flux:button>
                    @endcan
                @endif
            </flux:card>

            {{-- Members --}}
            <flux:card>
                <flux:heading size="lg" level="2">{{ __('Members') }}</flux:heading>

                <ul class="mt-3 divide-y divide-line" role="list">
                    @foreach ($this->members as $member)
                        <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between gap-3 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <x-avatar :user="$member" />

                                <span class="min-w-0 break-words text-sm font-semibold text-ink">
                                    {{ $member->name }}
                                    @if ($member->is(auth()->user()))
                                        <span class="font-normal text-muted">{{ __('(you)') }}</span>
                                    @endif
                                </span>
                            </div>

                            <x-chip :variant="$member->membership->role === HouseholdRole::Owner ? 'primary' : 'neutral'">
                                {{ ucfirst($member->membership->role->value) }}
                            </x-chip>
                        </li>
                    @endforeach
                </ul>

                @can('invite', $household)
                    <flux:button icon="user-plus" class="mt-2 w-full" wire:click="openInvite">{{ __('Invite housemate') }}</flux:button>
                @endcan
            </flux:card>
        </div>
    </div>
    @can('invite', $household)
        <flux:modal name="invite-housemate" class="w-full max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Invite a housemate') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('Send this link to one person. It works once and expires in :days days.', ['days' => CreateInvite::EXPIRES_AFTER_DAYS]) }}
                        {{ __('Once they\'ve joined, open this again for a link for the next person.') }}
                    </flux:text>
                </div>

                <flux:input
                    :value="$inviteUrl"
                    :label="__('Invite link')"
                    readonly
                    copyable
                    data-test="invite-url"
                />

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:button variant="ghost" icon="arrow-path" wire:click="newInviteLink" data-test="new-invite-link">
                        {{ __('New link') }}
                    </flux:button>

                    <flux:modal.close>
                        <flux:button variant="primary">{{ __('Done') }}</flux:button>
                    </flux:modal.close>
                </div>

                <flux:text size="sm">
                    {{ __('Sent it to the wrong person? Use New link and the old one stops working.') }}
                </flux:text>
            </div>
        </flux:modal>
    @endcan

    {{-- Add / edit bill --}}
    <flux:modal name="bill-form" class="w-full max-w-lg">
        <form wire:submit="saveBill" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $form->expenseId ? __('Edit bill') : __('Add a bill') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add bills once they\'re paid. Everyone\'s balance updates straight away.') }}</flux:text>
            </div>

            <flux:input wire:model="form.description" :label="__('What was it?')" :placeholder="__('e.g. Energy, Rent, Broadband')" maxlength="100" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.blur="form.amount" :label="__('Amount (:currency)', ['currency' => $currency])" inputmode="decimal" placeholder="0.00" required />
                <flux:input wire:model="form.spentOn" type="date" :label="__('Paid on')" :max="now()->toDateString()" required />
            </div>

            <flux:select wire:model="form.paidBy" :label="__('Paid by')">
                @foreach ($this->members as $member)
                    <flux:select.option :value="$member->id">{{ $member->is(auth()->user()) ? __(':name (you)', ['name' => $member->name]) : $member->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:radio.group wire:model.live="form.split" :label="__('Split')" variant="segmented">
                <flux:radio value="equal" :label="__('Equally')" />
                <flux:radio value="exact" :label="__('Exact amounts')" />
            </flux:radio.group>

            @if ($form->split === 'equal')
                <flux:checkbox.group wire:model="form.participants" :label="__('Shared between')">
                    @foreach ($this->members as $member)
                        <flux:checkbox :value="$member->id" :label="$member->name" />
                    @endforeach
                </flux:checkbox.group>
            @else
                <fieldset class="space-y-3">
                    <legend class="text-sm font-medium text-ink">{{ __('Each person\'s share') }}</legend>
                    @foreach ($this->members as $member)
                        <div class="flex items-center gap-3">
                            <x-avatar :user="$member" size="sm" />
                            <label for="exact-{{ $member->id }}" class="min-w-0 flex-1 truncate text-sm">{{ $member->name }}</label>
                            <div class="w-32">
                                <flux:input id="exact-{{ $member->id }}" wire:model="form.exactAmounts.{{ $member->id }}" inputmode="decimal" placeholder="0.00" />
                            </div>
                        </div>
                        <flux:error name="form.exactAmounts.{{ $member->id }}" />
                    @endforeach
                    <flux:text size="sm">{{ __('Leave anyone not sharing at 0. The amounts must add up to the bill.') }}</flux:text>
                </fieldset>
            @endif

            <flux:error name="form.shares" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-bill-button">
                    {{ $form->expenseId ? __('Save changes') : __('Add bill') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Settle up: record a payment you've made. It counts once the recipient confirms. --}}
    @can('create', [Settlement::class, $household])
        <flux:modal name="settle-up" class="w-full max-w-md">
            <form wire:submit="recordPayment" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Record a payment') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Pay them outside HousePot first (bank transfer, cash). They\'ll be asked to confirm it arrived, then balances update.') }}</flux:text>
                </div>

                <flux:select wire:model="payTo" :label="__('Who did you pay?')" :placeholder="__('Choose a housemate')" data-test="pay-to">
                    @foreach ($this->members as $member)
                        @continue($member->is(auth()->user()))
                        <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="payAmount" :label="__('Amount (:currency)', ['currency' => $currency])" inputmode="decimal" placeholder="0.00" required />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="record-payment-button">{{ __('Record payment') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    {{-- Delete confirmation --}}
    <flux:modal name="delete-bill" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Delete this bill?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Everyone\'s balance goes back to how it was before the bill. The change is kept in the household\'s history.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="deleteBill" data-test="delete-bill-button">{{ __('Delete bill') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
