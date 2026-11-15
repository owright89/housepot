<?php

use App\Actions\Households\CreateInvite;
use App\Enums\HouseholdRole;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use App\Support\DemoData;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Household $household;

    /**
     * Bills table filter: all, unpaid or paid.
     */
    public string $filter = 'all';

    /**
     * The link from the most recent invite, shown in the invite modal.
     */
    public ?string $inviteUrl = null;

    public function mount(): void
    {
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
     * Placeholder bills and balances until the ledger exists (step 5).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function demo(): array
    {
        $bills = DemoData::bills($this->members);
        $balances = DemoData::balances($this->members, Auth::user());

        return [
            'bills' => $bills,
            'balances' => $balances,
            'monthTotal' => DemoData::monthTotal($bills),
            'yourShare' => DemoData::yourShare($bills),
            'yourBalance' => DemoData::yourBalance($balances),
        ];
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
     * @return list<array<string, mixed>>
     */
    public function filteredBills(): array
    {
        return array_values(array_filter($this->demo['bills'], fn (array $bill) => match ($this->filter) {
            'unpaid' => $bill['status'] !== 'paid',
            'paid' => $bill['status'] === 'paid',
            default => true,
        }));
    }

    public function render()
    {
        return $this->view()->title($this->household->name);
    }
};
?>

@php($currency = $household->currency)
@php($demo = $this->demo)
@php($isOwner = $household->roleOf(auth()->user()) === HouseholdRole::Owner)

<section class="w-full">
    <flux:breadcrumbs class="mb-3">
        <flux:breadcrumbs.item :href="route('households.index')" wire:navigate>{{ __('Households') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $household->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <flux:heading size="xl" level="1" class="break-words text-2xl! font-extrabold!">{{ $household->name }}</flux:heading>
            <p class="mt-1 text-sm text-muted">
                {{ $household->currency }} · {{ trans_choice(':count member|:count members', $this->members->count()) }}@if ($isOwner) · {{ __('You\'re the owner') }}@endif
            </p>
        </div>

        <div class="flex gap-2">
            @can('invite', $household)
                <flux:button icon="user-plus" wire:click="openInvite" data-test="invite-button">{{ __('Invite') }}</flux:button>
            @endcan
            @can('create', [Expense::class, $household])
                <flux:button variant="primary" icon="plus" data-test="add-bill-button">{{ __('Add bill') }}</flux:button>
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

    {{-- PLACEHOLDER figures until the ledger exists (step 5). --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text size="sm">{{ __('Total this month') }}</flux:text>
            <p class="mt-1 text-2xl font-extrabold text-ink">{{ Money::format($demo['monthTotal'], $currency) }}</p>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Your share') }}</flux:text>
            <p class="mt-1 text-2xl font-extrabold text-ink">{{ Money::format($demo['yourShare'], $currency) }}</p>
        </flux:card>
        <flux:card>
            <flux:text size="sm">{{ __('Your balance') }}</flux:text>
            <p @class(['mt-1 text-2xl font-extrabold', 'text-primary-text' => $demo['yourBalance'] > 0, 'text-coral' => $demo['yourBalance'] < 0, 'text-ink' => $demo['yourBalance'] === 0])>
                {{ Money::signed($demo['yourBalance'], $currency) }}
            </p>
            <flux:text size="sm" class="mt-1">
                {{ $demo['yourBalance'] > 0 ? __('You\'re owed overall') : ($demo['yourBalance'] < 0 ? __('You owe overall') : __('All square')) }}
            </flux:text>
        </flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Bills --}}
        <flux:card class="p-0! lg:col-span-2" id="bills">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <flux:heading size="lg" level="2">{{ __('Bills · :month', ['month' => now()->format('F')]) }}</flux:heading>

                <div class="flex rounded-(--radius-control) bg-subtle p-1" role="group" aria-label="{{ __('Filter bills') }}">
                    @foreach (['all' => __('All'), 'unpaid' => __('Unpaid'), 'paid' => __('Paid')] as $value => $label)
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

            <div class="overflow-x-auto border-t border-line">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead>
                        <tr class="text-xs text-muted">
                            <th scope="col" class="px-5 py-2 text-start font-medium">{{ __('Bill') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Paid by') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Due') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Amount') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Your share') }}</th>
                            <th scope="col" class="px-5 py-2 text-end font-medium">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line border-t border-line">
                        @forelse ($this->filteredBills() as $bill)
                            <tr>
                                <td class="px-5 py-3 font-bold text-ink">{{ $bill['name'] }}</td>
                                <td class="px-3 py-3">
                                    <span class="flex items-center gap-2 text-muted">
                                        <x-avatar :user="$bill['payer']" size="sm" />
                                        {{ $bill['payer']->is(auth()->user()) ? __('You') : Str::before($bill['payer']->name, ' ') }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-muted">{{ Str::after($bill['due'], ' ') }}</td>
                                <td class="px-3 py-3 text-end text-muted">{{ Money::format($bill['total'], $currency) }}</td>
                                <td class="px-3 py-3 text-end font-bold text-ink">{{ Money::format($bill['share'], $currency) }}</td>
                                <td class="px-5 py-3 text-end"><x-bill-status :status="$bill['status']" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-8 text-center text-muted">{{ __('No bills here.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <div class="flex flex-col gap-6">
            {{-- Who owes who (placeholder) --}}
            <flux:card>
                <flux:heading size="lg" level="2">{{ __('Who owes who') }}</flux:heading>

                @if (empty($demo['balances']))
                    <flux:text class="mt-3">{{ __('Nothing to settle yet.') }}</flux:text>
                @else
                    <ul class="mt-4 space-y-3 text-sm" role="list">
                        @foreach ($demo['balances'] as $balance)
                            <li class="flex items-center justify-between gap-3">
                                <span class="text-muted">
                                    @if ($balance['amount'] > 0)
                                        {{ Str::before($balance['user']->name, ' ') }} {{ __('owes') }} <strong class="text-ink">{{ __('you') }}</strong>
                                    @else
                                        <strong class="text-ink">{{ __('You') }}</strong> {{ __('owe') }} {{ Str::before($balance['user']->name, ' ') }}
                                    @endif
                                </span>
                                <span @class(['font-bold', 'text-primary-text' => $balance['amount'] > 0, 'text-coral' => $balance['amount'] < 0])>
                                    {{ Money::format(abs($balance['amount']), $currency) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <flux:button variant="primary" class="mt-4 w-full">{{ __('Settle up') }}</flux:button>
                @endif
            </flux:card>

            {{-- Members (real data) --}}
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
</section>
