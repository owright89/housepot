<?php

namespace App\Models;

use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Who changed what, and when. Written by actions inside the same transaction as the change.
 *
 * @property int $id
 * @property int $household_id
 * @property int $user_id
 * @property string $subject_type
 * @property int $subject_id
 * @property AuditEvent $event
 * @property array<string, array{0: mixed, 1: mixed}> $changes field => [old, new]
 * @property Carbon $created_at
 */
#[Fillable(['household_id', 'user_id', 'subject_type', 'subject_id', 'event', 'changes'])]
class Audit extends Model
{
    public const UPDATED_AT = null;

    /**
     * Audits are immutable, like the ledger.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audits are immutable.'));
        static::deleting(fn () => throw new LogicException('Audits are immutable.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record a change.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     */
    public static function record(User $actor, Household $household, Model $subject, AuditEvent $event, array $changes): self
    {
        return self::create([
            'household_id' => $household->id,
            'user_id' => $actor->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'changes' => $changes,
        ]);
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * The user who made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }
}
