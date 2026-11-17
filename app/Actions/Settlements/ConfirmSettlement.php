<?php

namespace App\Actions\Settlements;

use App\Enums\AuditEvent;
use App\Enums\SettlementStatus;
use App\Events\BalancesUpdated;
use App\Ledger\InvalidSettlement;
use App\Ledger\Ledger;
use App\Models\Audit;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfirmSettlement
{
    /**
     * The recipient confirms the money arrived. The settlement is marked confirmed and posted
     * to the ledger (payer +amount, recipient −amount), with an audit row, in one transaction.
     *
     * Authorisation (SettlementPolicy::respond) is the caller's job.
     *
     * @throws InvalidSettlement
     */
    public function handle(User $actor, Settlement $settlement): Settlement
    {
        return DB::transaction(function () use ($actor, $settlement): Settlement {
            // Lock the row so a double-click or a second tab waits here, then sees it's no longer pending.
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $household = $settlement->household;

            if ($household->isArchived()) {
                throw new InvalidSettlement(__('This household is archived, so payments can\'t be changed.'));
            }

            if ($settlement->status !== SettlementStatus::Pending) {
                throw new InvalidSettlement(__('This payment has already been answered.'));
            }

            $settlement->forceFill([
                'status' => SettlementStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            Ledger::post($household, $settlement, __(':from paid :to', [
                'from' => $settlement->fromUser->name,
                'to' => $settlement->toUser->name,
            ]), [
                $settlement->from_user_id => $settlement->amount,
                $settlement->to_user_id => -$settlement->amount,
            ]);

            Audit::record($actor, $household, $settlement, AuditEvent::Confirmed, [
                'status' => [SettlementStatus::Pending->value, SettlementStatus::Confirmed->value],
            ]);

            // Sent after the transaction commits (ShouldDispatchAfterCommit).
            BalancesUpdated::dispatch($household->id);

            return $settlement;
        });
    }
}
