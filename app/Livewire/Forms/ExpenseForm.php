<?php

namespace App\Livewire\Forms;

use App\Actions\Expenses\ExpenseData;
use App\Ledger\InvalidSplit;
use App\Ledger\SplitCalculator;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

/**
 * The Add bill / Edit bill form. Holds what the user typed and turns it into ExpenseData:
 * amounts are parsed to pence and shares worked out by SplitCalculator.
 */
class ExpenseForm extends Form
{
    /** Set when editing; null when adding. */
    public ?int $expenseId = null;

    /** The version that was loaded, for optimistic locking. */
    public int $version = 1;

    public string $description = '';

    public string $amount = '';

    public string $spentOn = '';

    public ?int $paidBy = null;

    /** equal | exact */
    public string $split = 'equal';

    /** @var array<int, int|string> user ids sharing an equal split */
    public array $participants = [];

    /** @var array<int|string, string> user id => typed amount, for an exact split */
    public array $exactAmounts = [];

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', 'max:20'],
            'spentOn' => ['required', 'date', 'before_or_equal:today'],
            'paidBy' => ['required', 'integer'],
            'split' => ['required', 'in:equal,exact'],
            'participants' => ['array'],
            'exactAmounts' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['spentOn' => 'date', 'paidBy' => 'payer'];
    }

    /**
     * A blank form: paid by the viewer today, split equally between everyone.
     */
    public function start(Household $household, User $viewer): void
    {
        $this->reset();
        $this->resetErrorBag();

        $this->spentOn = now()->toDateString();
        $this->paidBy = $viewer->id;
        $this->participants = $household->members()->pluck('users.id')->all();
    }

    /**
     * Load an existing bill for editing.
     */
    public function edit(Expense $expense): void
    {
        $this->reset();
        $this->resetErrorBag();

        $shares = $expense->splits()->pluck('amount', 'user_id')->map(fn ($pence) => (int) $pence);
        $sharing = $shares->filter(fn (int $pence) => $pence > 0);
        $isEqual = $sharing->isNotEmpty()
            && SplitCalculator::equal($expense->amount, array_values(array_map('intval', $sharing->keys()->all()))) === $sharing->sortKeys()->all();

        $this->expenseId = $expense->id;
        $this->version = $expense->version;
        $this->description = $expense->description;
        $this->amount = Money::toInput($expense->amount);
        $this->spentOn = $expense->spent_on->toDateString();
        $this->paidBy = $expense->paid_by;
        $this->split = $isEqual ? 'equal' : 'exact';
        $this->participants = $sharing->keys()->all();
        $this->exactAmounts = $shares->map(fn (int $pence) => Money::toInput($pence))->all();
    }

    /**
     * Validate and convert to ExpenseData. Field errors are shown next to their inputs.
     *
     * @throws ValidationException
     */
    public function toData(Household $household): ExpenseData
    {
        $this->validate();

        $members = $household->members()->get()->keyBy('id');

        $total = Money::parse($this->amount);

        if ($total === null || $total === 0) {
            $this->fail('amount', __('Enter an amount like 12.50.'));
        }

        $payer = $members->get((int) $this->paidBy) ?? $this->fail('paidBy', __('Choose who paid.'));

        try {
            $shares = $this->split === 'equal'
                ? SplitCalculator::equal($total, array_values(array_map('intval', $this->participants)))
                : SplitCalculator::exact($total, $this->parsedExactAmounts($members->keys()->all()));
        } catch (InvalidSplit $e) {
            // Not 'split': that key is bound to the Split buttons, so Flux would show it there as well.
            $this->fail('shares', $e->getMessage());
        }

        return new ExpenseData(
            payer: $payer,
            description: trim($this->description),
            amount: $total,
            spentOn: Carbon::parse($this->spentOn),
            shares: $shares,
        );
    }

    /**
     * @param  array<int, int|string>  $memberIds
     * @return array<int, int>
     */
    private function parsedExactAmounts(array $memberIds): array
    {
        $amounts = [];

        foreach (array_map('intval', $memberIds) as $userId) {
            $typed = trim((string) ($this->exactAmounts[$userId] ?? ''));
            $pence = $typed === '' ? 0 : Money::parse($typed);

            if ($pence === null) {
                $this->fail("exactAmounts.{$userId}", __('Enter an amount like 12.50.'));
            }

            $amounts[$userId] = $pence;
        }

        return $amounts;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$this->getPropertyName().'.'.$field => $message]);
    }
}
