<?php

namespace App\Ledger;

use App\Models\Household;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only code that writes journal entries and lines.
 *
 * Lines are signed pence per user: positive = owed money, negative = owes money.
 * Every entry must sum to zero, and posted entries are never changed: corrections
 * are made by posting a reversal (CLAUDE.md, Ledger rules).
 */
class Ledger
{
    /**
     * Post an entry. Amounts for the same user are combined into one line, and
     * zero lines are dropped (a payer who only paid their own share nets to nothing).
     *
     * @param  array<int, int>  $amounts  user id => signed pence
     *
     * @throws UnbalancedEntry
     */
    public static function post(Household $household, Model $source, string $description, array $amounts, ?JournalEntry $reverses = null): JournalEntry
    {
        $lines = array_filter($amounts, fn (int $amount) => $amount !== 0);
        ksort($lines);

        if (array_sum($lines) !== 0) {
            throw new UnbalancedEntry(sprintf(
                'Journal entry "%s" sums to %d, not 0.',
                $description,
                array_sum($lines),
            ));
        }

        return DB::transaction(function () use ($household, $source, $description, $lines, $reverses): JournalEntry {
            $entry = JournalEntry::create([
                'household_id' => $household->id,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'reverses_entry_id' => $reverses?->id,
                'description' => $description,
                'posted_at' => now(),
            ]);

            foreach ($lines as $userId => $amount) {
                $entry->lines()->create([
                    'household_id' => $household->id,
                    'user_id' => $userId,
                    'amount' => $amount,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Post the mirror image of an entry, cancelling it out. An entry can only be
     * reversed once (unique reverses_entry_id).
     */
    public static function reverse(JournalEntry $entry, string $description): JournalEntry
    {
        $amounts = $entry->lines()
            ->pluck('amount', 'user_id')
            ->map(fn (int $amount) => -$amount)
            ->all();

        return self::post($entry->household, $entry->source, $description, $amounts, reverses: $entry);
    }

    /**
     * The entry currently in force for a source: posted, not a reversal, not yet reversed.
     */
    public static function activeEntryFor(Model $source): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->latest('id')
            ->first();
    }

    /**
     * Each user's balance in the household: the sum of their lines.
     * Postgres returns SUM() as a numeric string, so it is cast to int.
     *
     * @return array<int, int> user id => signed pence, sorted by user id
     */
    public static function balances(Household $household): array
    {
        return DB::table('journal_lines')
            ->where('household_id', $household->id)
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->selectRaw('user_id, SUM(amount) AS balance')
            ->pluck('balance', 'user_id')
            ->map(fn ($balance) => (int) $balance)
            ->all();
    }
}
