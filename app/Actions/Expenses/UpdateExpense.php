<?php

namespace App\Actions\Expenses;

use App\Enums\AuditEvent;
use App\Events\BalancesUpdated;
use App\Events\ExpenseUpdated;
use App\Ledger\InvalidExpense;
use App\Ledger\Ledger;
use App\Ledger\StaleExpense;
use App\Models\Audit;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateExpense
{
    /**
     * Edit a bill. If the money changes, the old ledger entry is reversed and a new one
     * posted; posted lines are never edited. Renaming or re-dating only updates the bill.
     *
     * $expectedVersion is the version the editor loaded. If someone saved in between,
     * StaleExpense is thrown instead of silently overwriting their change.
     *
     * Authorisation (ExpensePolicy::update) is the caller's job.
     *
     * @throws InvalidExpense
     * @throws StaleExpense
     */
    public function handle(User $actor, Expense $expense, int $expectedVersion, ExpenseData $data): Expense
    {
        $data->assertValidFor($expense->household);

        return DB::transaction(function () use ($actor, $expense, $expectedVersion, $data): Expense {
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($expense->version !== $expectedVersion) {
                throw new StaleExpense;
            }

            $before = ExpenseData::fromExpense($expense);
            $changes = self::diff($before->toAuditArray(), $data->toAuditArray());

            if ($changes === []) {
                return $expense;
            }

            $expense->forceFill([
                'paid_by' => $data->payer->id,
                'description' => $data->description,
                'amount' => $data->amount,
                'spent_on' => $data->spentOn,
                'category' => $data->category,
                'version' => $expense->version + 1,
            ])->save();

            $moneyChanged = $data->affectsMoneyComparedTo($before);

            if ($moneyChanged) {
                $expense->splits()->delete();

                foreach ($data->shares as $userId => $share) {
                    $expense->splits()->create(['user_id' => $userId, 'amount' => $share]);
                }

                $active = Ledger::activeEntryFor($expense);

                if ($active !== null) {
                    Ledger::reverse($active, __('Edited: :bill', ['bill' => $before->description]));
                }

                Ledger::post($expense->household, $expense, $data->description, $data->ledgerAmounts());
            }

            Audit::record($actor, $expense->household, $expense, AuditEvent::Updated, $changes);

            ExpenseUpdated::dispatch($expense, $actor);

            if ($moneyChanged) {
                BalancesUpdated::dispatch($expense->household_id);
            }

            return $expense;
        });
    }

    /**
     * Fields whose value changed, as field => [old, new].
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function diff(array $old, array $new): array
    {
        $changes = [];

        foreach ($new as $field => $value) {
            if ($old[$field] !== $value) {
                $changes[$field] = [$old[$field], $value];
            }
        }

        return $changes;
    }
}
