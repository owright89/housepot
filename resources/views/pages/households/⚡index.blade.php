<?php

use App\Actions\Households\CreateHousehold;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Ledger\HouseholdSummary;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Households')] class extends Component {
    /**
     * Currencies a household can use, keyed by ISO code.
     */
    public const CURRENCIES = [
        'GBP' => 'GBP — British pound',
        'EUR' => 'EUR — Euro',
        'USD' => 'USD — US dollar',
    ];

    #[Validate('required|string|max:60')]
    public string $name = '';

    #[Validate('required|in:GBP,EUR,USD')]
    public string $currency = 'GBP';

    /**
     * The user's current households, with a count of current members.
     *
     * @return Collection<int, Household>
     */
    #[Computed]
    public function households(): Collection
    {
        return Auth::user()
            ->households()
            ->with(['members' => fn ($query) => $query->orderBy('name')])
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /**
     * Card figures update live when a bill changes in any of the user's households.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $listeners = ['echo-reconnected' => '$refresh'];

        foreach ($this->households as $household) {
            $listeners["echo-private:households.{$household->id},.balances.updated"] = '$refresh';
        }

        return $listeners;
    }

    /**
     * The viewer's balance and this month's total for a household card.
     *
     * @return array{balance: int, month: int}
     */
    public function figures(Household $household): array
    {
        $summary = HouseholdSummary::for($household, Auth::user());

        return ['balance' => $summary->yourBalance, 'month' => $summary->monthTotal];
    }

    /**
     * Create a household owned by the current user.
     */
    public function create(CreateHousehold $createHousehold): void
    {
        $this->validate();

        $createHousehold->handle(Auth::user(), $this->name, $this->currency);

        $this->reset('name', 'currency');
        unset($this->households);

        Flux::modal('create-household')->close();
        Flux::toast(variant: 'success', text: __('Household created.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1" class="text-2xl! font-extrabold!">{{ __('Households') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The homes you share costs with.') }}</flux:text>
        </div>

        @if ($this->households->isNotEmpty())
            <flux:modal.trigger name="create-household">
                <flux:button variant="primary" icon="plus" data-test="new-household-button">
                    {{ __('New household') }}
                </flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if (session('invite_error'))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6" data-test="invite-error">
            <flux:callout.heading>{{ __('Couldn\'t join that household') }}</flux:callout.heading>
            <flux:callout.text>{{ session('invite_error') }} {{ __('Ask the owner for a new link.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->households->isEmpty())
        <flux:card class="mx-auto flex max-w-lg flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-(--radius-control) bg-primary-soft p-3">
                <flux:icon.home-modern class="size-6 text-primary-text" aria-hidden="true" />
            </div>

            <div class="space-y-1">
                <flux:heading size="lg" level="2">{{ __('No households yet') }}</flux:heading>
                <flux:text>
                    {{ __('Create one for your flat, then invite your flatmates to start splitting bills.') }}
                </flux:text>
            </div>

            <flux:modal.trigger name="create-household">
                <flux:button variant="primary" icon="plus" data-test="create-first-household-button">
                    {{ __('Create a household') }}
                </flux:button>
            </flux:modal.trigger>

            <flux:text size="sm">
                {{ __('Got an invite link? Open it and you\'ll join automatically.') }}
            </flux:text>
        </flux:card>
    @else
        <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" role="list">
            @foreach ($this->households as $household)
                @php($figures = $this->figures($household))
                <li wire:key="household-{{ $household->id }}">
                    <a href="{{ route('households.show', $household) }}" wire:navigate class="group block h-full rounded-(--radius-card)">
                        <flux:card class="flex h-full flex-col gap-4 transition-colors group-hover:bg-subtle">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <flux:heading size="lg" level="2" class="break-words font-bold!">{{ $household->name }}</flux:heading>
                                    <p class="mt-0.5 text-sm text-muted">
                                        {{ $household->currency }} · {{ trans_choice(':count member|:count members', $household->members_count) }}
                                    </p>
                                </div>

                                <x-chip :variant="$household->membership->role === HouseholdRole::Owner ? 'primary' : 'neutral'">
                                    {{ ucfirst($household->membership->role->value) }}
                                </x-chip>
                            </div>

                            @if ($household->members_count > 1)
                                <div class="flex -space-x-1">
                                    @foreach ($household->members->take(5) as $member)
                                        <x-avatar :user="$member" class="ring-2 ring-card" />
                                    @endforeach
                                </div>
                            @else
                                <div class="flex items-center gap-2">
                                    <x-avatar :user="$household->members->first()" />
                                    <span class="text-sm text-muted">{{ __('Invite your housemates') }}</span>
                                </div>
                            @endif

                            <div class="mt-auto flex items-end justify-between border-t border-line pt-3">
                                <div>
                                    <p class="text-xs text-muted">{{ __('Your balance') }}</p>
                                    <p @class(['text-base font-bold', 'text-primary-text' => $figures['balance'] > 0, 'text-coral' => $figures['balance'] < 0, 'text-ink' => $figures['balance'] === 0])>
                                        {{ Money::signed($figures['balance'], $household->currency) }}
                                    </p>
                                </div>
                                <div class="text-end">
                                    <p class="text-xs text-muted">{{ __('This month') }}</p>
                                    <p class="text-base font-bold text-ink">
                                        {{ $figures['month'] > 0 ? Money::format($figures['month'], $household->currency) : __('No bills yet') }}
                                    </p>
                                </div>
                            </div>
                        </flux:card>
                    </a>
                </li>
            @endforeach

            <li>
                <flux:modal.trigger name="create-household">
                    <button type="button" class="flex h-full min-h-48 w-full flex-col items-center justify-center gap-2 rounded-(--radius-card) border border-dashed border-line p-6 text-center transition-colors hover:bg-subtle">
                        <span class="flex size-9 items-center justify-center rounded-(--radius-control) bg-subtle text-muted">
                            <flux:icon.plus variant="micro" aria-hidden="true" />
                        </span>
                        <span class="text-sm font-bold text-ink">{{ __('Start a new household') }}</span>
                        <span class="text-xs text-muted">{{ __('Add your home, then invite everyone') }}</span>
                    </button>
                </flux:modal.trigger>
            </li>
        </ul>
    @endif

    <flux:modal name="create-household" class="w-full max-w-md">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New household') }}</flux:heading>
                <flux:text class="mt-2">{{ __('You\'ll be the owner, and can invite flatmates next.') }}</flux:text>
            </div>

            <flux:input
                wire:model="name"
                :label="__('Household name')"
                :placeholder="__('e.g. 12 Elm Street')"
                maxlength="60"
                required
                autofocus
            />

            <flux:select
                wire:model="currency"
                :label="__('Currency')"
                :description="__('This can\'t be changed later.')"
            >
                @foreach (self::CURRENCIES as $code => $label)
                    <flux:select.option :value="$code">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" data-test="create-household-button">
                    {{ __('Create household') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
