<?php

namespace App\Actions\Expenses;

use App\Enums\AuditEvent;
use App\Events\BalancesUpdated;
use App\Events\ExpenseRecorded;
use App\Ledger\InvalidExpense;
use App\Ledger\Ledger;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordExpense
{
    /**
     * Record a paid bill: the expense, its splits, the ledger entry and the audit row,
     * all in one transaction.
     *
     * Authorisation (ExpensePolicy::create) is the caller's job.
     *
     * @throws InvalidExpense
     */
    public function handle(User $actor, Household $household, ExpenseData $data): Expense
    {
        $data->assertValidFor($household);

        return DB::transaction(function () use ($actor, $household, $data): Expense {
            $expense = $household->expenses()->create([
                'paid_by' => $data->payer->id,
                'description' => $data->description,
                'amount' => $data->amount,
                'spent_on' => $data->spentOn,
                'category' => $data->category,
            ]);

            foreach ($data->shares as $userId => $share) {
                $expense->splits()->create(['user_id' => $userId, 'amount' => $share]);
            }

            Ledger::post($household, $expense, $data->description, $data->ledgerAmounts());

            Audit::record($actor, $household, $expense, AuditEvent::Created, array_map(
                fn ($value) => [null, $value],
                $data->toAuditArray(),
            ));

            // Sent after the transaction commits (ShouldDispatchAfterCommit).
            ExpenseRecorded::dispatch($expense, $actor);
            BalancesUpdated::dispatch($household->id);

            return $expense;
        });
    }
}
