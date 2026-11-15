<?php

namespace App\Actions\Expenses;

use App\Ledger\InvalidExpense;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The details of a bill, as entered: who paid, how much, and each person's share.
 * Shares come from App\Ledger\SplitCalculator and must add up to the amount.
 */
final readonly class ExpenseData
{
    /**
     * @param  array<int, int>  $shares  user id => pence
     */
    public function __construct(
        public User $payer,
        public string $description,
        public int $amount,
        public CarbonInterface $spentOn,
        public array $shares,
        public ?string $category = null,
    ) {}

    /**
     * The current details of an existing expense, for comparing against an edit.
     */
    public static function fromExpense(Expense $expense): self
    {
        return new self(
            payer: $expense->payer,
            description: $expense->description,
            amount: $expense->amount,
            spentOn: $expense->spent_on,
            shares: $expense->splits()->orderBy('user_id')->pluck('amount', 'user_id')->map(fn ($pence) => (int) $pence)->all(),
            category: $expense->category,
        );
    }

    /**
     * Check the bill makes sense for this household before anything is written.
     *
     * @throws InvalidExpense
     */
    public function assertValidFor(Household $household): void
    {
        if ($household->isArchived()) {
            throw new InvalidExpense(__('This household is archived, so bills can\'t be changed.'));
        }

        if (trim($this->description) === '') {
            throw new InvalidExpense(__('Give the bill a name.'));
        }

        if ($this->amount <= 0) {
            throw new InvalidExpense(__('The bill amount must be more than zero.'));
        }

        if ($this->shares === [] || array_sum($this->shares) !== $this->amount) {
            throw new InvalidExpense(__('Everyone\'s shares must add up to the bill amount.'));
        }

        $memberIds = $household->members()->pluck('users.id')->all();

        foreach ([$this->payer->id, ...array_keys($this->shares)] as $userId) {
            if (! in_array($userId, $memberIds, true)) {
                throw new InvalidExpense(__('Everyone on a bill must be a current member of the household.'));
            }
        }
    }

    /**
     * Ledger lines: the payer is owed the full amount, everyone sharing owes their share.
     * The payer's own share nets off against what they paid.
     *
     * @return array<int, int> user id => signed pence
     */
    public function ledgerAmounts(): array
    {
        $amounts = [$this->payer->id => $this->amount];

        foreach ($this->shares as $userId => $share) {
            $amounts[$userId] = ($amounts[$userId] ?? 0) - $share;
        }

        return $amounts;
    }

    /**
     * Comparable values for the audit trail.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        $shares = $this->shares;
        ksort($shares);

        return [
            'description' => $this->description,
            'amount' => $this->amount,
            'paid_by' => $this->payer->id,
            'spent_on' => Carbon::parse($this->spentOn)->toDateString(),
            'category' => $this->category,
            'shares' => $shares,
        ];
    }

    /**
     * Whether a change affects who owes what (and so needs a new ledger entry).
     */
    public function affectsMoneyComparedTo(self $before): bool
    {
        $after = $this->toAuditArray();
        $old = $before->toAuditArray();

        return $after['amount'] !== $old['amount']
            || $after['paid_by'] !== $old['paid_by']
            || $after['shares'] !== $old['shares'];
    }
}
