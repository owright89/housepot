<?php

namespace App\Actions\Settlements;

use App\Enums\AuditEvent;
use App\Enums\SettlementStatus;
use App\Ledger\InvalidSettlement;
use App\Models\Audit;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectSettlement
{
    /**
     * The recipient says the money never arrived. The settlement is marked rejected with an
     * audit row. Nothing was posted to the ledger, so balances don't change.
     *
     * Authorisation (SettlementPolicy::respond) is the caller's job.
     *
     * @throws InvalidSettlement
     */
    public function handle(User $actor, Settlement $settlement): Settlement
    {
        return DB::transaction(function () use ($actor, $settlement): Settlement {
            // Lock the row so a confirm and a reject can't both win.
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $household = $settlement->household;

            if ($household->isArchived()) {
                throw new InvalidSettlement(__('This household is archived, so payments can\'t be changed.'));
            }

            if ($settlement->status !== SettlementStatus::Pending) {
                throw new InvalidSettlement(__('This payment has already been answered.'));
            }

            $settlement->forceFill(['status' => SettlementStatus::Rejected])->save();

            Audit::record($actor, $household, $settlement, AuditEvent::Rejected, [
                'status' => [SettlementStatus::Pending->value, SettlementStatus::Rejected->value],
            ]);

            return $settlement;
        });
    }
}
