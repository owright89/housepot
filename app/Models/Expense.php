<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $household_id
 * @property int $paid_by
 * @property string $description
 * @property int $amount Pence.
 * @property ExpenseCategory|null $category Null on bills recorded before categories existed.
 * @property Carbon $spent_on
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['household_id', 'paid_by', 'description', 'amount', 'category', 'spent_on'])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'spent_on' => 'date',
            'version' => 'integer',
            'category' => ExpenseCategory::class,
        ];
    }

    /**
     * The category to show: bills recorded before categories existed count as Other.
     */
    public function displayCategory(): ExpenseCategory
    {
        return $this->category ?? ExpenseCategory::Other;
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
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return HasMany<ExpenseSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(ExpenseSplit::class);
    }

    /**
     * @return MorphMany<JournalEntry, $this>
     */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
