<?php

namespace App\Actions\Settlements;

use App\Enums\AuditEvent;
use App\Ledger\InvalidSettlement;
use App\Models\Audit;
use App\Models\Household;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestSettlement
{
    /**
     * Record that $from paid $to. It stays pending, with no ledger lines, until $to confirms.
     *
     * Authorisation (SettlementPolicy::create) is the caller's job.
     *
     * @throws InvalidSettlement
     */
    public function handle(User $from, Household $household, User $to, int $amount): Settlement
    {
        if ($household->isArchived()) {
            throw new InvalidSettlement(__('This household is archived, so payments can\'t be recorded.'));
        }

        if ($amount <= 0) {
            throw new InvalidSettlement(__('The amount must be more than zero.'));
        }

        if ($from->is($to)) {
            throw new InvalidSettlement(__('You can\'t record a payment to yourself.'));
        }

        if (! $household->hasMember($from) || ! $household->hasMember($to)) {
            throw new InvalidSettlement(__('Both people must be current members of the household.'));
        }

        return DB::transaction(function () use ($from, $household, $to, $amount): Settlement {
            $settlement = $household->settlements()->create([
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'amount' => $amount,
            ]);

            Audit::record($from, $household, $settlement, AuditEvent::Created, [
                'to_user_id' => [null, $to->id],
                'amount' => [null, $amount],
            ]);

            return $settlement;
        });
    }
}
