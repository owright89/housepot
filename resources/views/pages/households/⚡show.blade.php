<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Household $household;

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

    public function render()
    {
        return $this->view()->title($this->household->name);
    }
};
?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1" class="break-words">{{ $household->name }}</flux:heading>
            <flux:subheading class="flex items-center gap-2">
                <span>{{ $household->currency }}</span>
                <span aria-hidden="true">·</span>
                <span>{{ trans_choice(':count member|:count members', $this->members->count()) }}</span>
            </flux:subheading>
        </div>
    </div>

    @if ($household->isArchived())
        <flux:callout variant="secondary" icon="archive-box" class="mb-6 max-w-2xl">
            <flux:callout.heading>{{ __('This household is archived') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Archived on :date. You can still see its members and history, but nothing can be changed.', ['date' => $household->archived_at->toFormattedDateString()]) }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="max-w-2xl">
        <flux:heading size="lg" level="2">{{ __('Members') }}</flux:heading>

        <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700" role="list">
            @foreach ($this->members as $member)
                <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between gap-3 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <flux:avatar size="sm" :initials="$member->initials()" aria-hidden="true" />

                        <flux:text class="min-w-0 break-words font-medium text-zinc-800 dark:text-white">
                            {{ $member->name }}
                            @if ($member->is(auth()->user()))
                                <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ __('(you)') }}</span>
                            @endif
                        </flux:text>
                    </div>

                    <flux:badge size="sm" class="shrink-0" :color="$member->membership->role === HouseholdRole::Owner ? 'lime' : 'zinc'">
                        {{ ucfirst($member->membership->role->value) }}
                    </flux:badge>
                </li>
            @endforeach
        </ul>
    </flux:card>
</section>
