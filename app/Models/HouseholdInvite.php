<?php

namespace App\Models;

use Database\Factories\HouseholdInviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A single-use, expiring invite link to join a household.
 *
 * @property int $id
 * @property int $household_id
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'invited_by', 'expires_at'])]
class HouseholdInvite extends Model
{
    /** @use HasFactory<HouseholdInviteFactory> */
    use HasFactory, Prunable;

    /**
     * Unused invites are deleted this many days after they stop working.
     * Accepted invites are kept as a record of who invited whom.
     */
    public const PRUNE_AFTER_DAYS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Invites that can still be used: not accepted, revoked or expired. Mirrors isUsable().
     *
     * @param  Builder<HouseholdInvite>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Unused invites that stopped working (revoked or expired) more than PRUNE_AFTER_DAYS ago.
     * Run by `php artisan model:prune`, scheduled daily in routes/console.php.
     *
     * @return Builder<HouseholdInvite>
     */
    public function prunable(): Builder
    {
        $cutoff = now()->subDays(self::PRUNE_AFTER_DAYS);

        return self::query()
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query
                ->where('revoked_at', '<', $cutoff)
                ->orWhere('expires_at', '<', $cutoff));
    }

    /**
     * Whether the invite can still be used: not accepted, revoked or expired.
     */
    public function isUsable(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * The signed link to share. Anyone signed in who opens it joins the household.
     */
    public function url(): string
    {
        return URL::signedRoute('invites.accept', ['invite' => $this]);
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
