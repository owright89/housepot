<?php

use App\Actions\Households\CreateHousehold;
use App\Enums\HouseholdRole;
use App\Models\Household;
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
            ->withCount('members')
            ->orderBy('name')
            ->get();
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
            <flux:heading size="xl" level="1">{{ __('Households') }}</flux:heading>
            <flux:subheading>{{ __('The homes you share costs with.') }}</flux:subheading>
        </div>

        @if ($this->households->isNotEmpty())
            <flux:modal.trigger name="create-household">
                <flux:button variant="primary" icon="plus" data-test="new-household-button">
                    {{ __('New household') }}
                </flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($this->households->isEmpty())
        <flux:card class="mx-auto flex max-w-lg flex-col items-center gap-4 py-12 text-center">
            <div class="rounded-full bg-zinc-100 p-3 dark:bg-zinc-700">
                <flux:icon.home-modern class="size-6 text-zinc-500 dark:text-zinc-300" aria-hidden="true" />
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
                <li wire:key="household-{{ $household->id }}">
                    <a href="{{ route('households.show', $household) }}" wire:navigate class="block h-full rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-zinc-400">
                        <flux:card class="flex h-full flex-col gap-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700">
                            <div class="flex items-start justify-between gap-3">
                                <flux:heading size="lg" level="2" class="break-words">{{ $household->name }}</flux:heading>

                                <flux:badge size="sm" :color="$household->membership->role === HouseholdRole::Owner ? 'lime' : 'zinc'">
                                    {{ ucfirst($household->membership->role->value) }}
                                </flux:badge>
                            </div>

                            <flux:text class="flex items-center gap-2">
                                <span>{{ $household->currency }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ trans_choice(':count member|:count members', $household->members_count) }}</span>
                            </flux:text>
                        </flux:card>
                    </a>
                </li>
            @endforeach
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
