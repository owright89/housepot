<?php

namespace App\Actions\Expenses;

use App\Enums\AuditEvent;
use App\Ledger\InvalidExpense;
use App\Ledger\Ledger;
use App\Ledger\StaleExpense;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteExpense
{
    /**
     * Delete a bill: reverse its ledger entry, record the audit, then soft-delete it.
     * The history stays in the ledger and audit trail.
     *
     * Authorisation (ExpensePolicy::delete) is the caller's job.
     *
     * @throws InvalidExpense
     * @throws StaleExpense
     */
    public function handle(User $actor, Expense $expense, int $expectedVersion): void
    {
        if ($expense->household->isArchived()) {
            throw new InvalidExpense(__('This household is archived, so bills can\'t be changed.'));
        }

        DB::transaction(function () use ($actor, $expense, $expectedVersion): void {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->version !== $expectedVersion) {
                throw new StaleExpense;
            }

            $active = Ledger::activeEntryFor($expense);

            if ($active !== null) {
                Ledger::reverse($active, __('Deleted: :bill', ['bill' => $expense->description]));
            }

            Audit::record($actor, $expense->household, $expense, AuditEvent::Deleted, array_map(
                fn ($value) => [$value, null],
                ExpenseData::fromExpense($expense)->toAuditArray(),
            ));

            $expense->forceFill(['version' => $expense->version + 1])->save();
            $expense->delete();
        });
    }
}
