<?php

namespace App\Enums;

/**
 * What a bill was for. A fixed list keeps chart colours stable and filtering simple;
 * per-household custom categories would need their own table (Phase 2).
 */
enum ExpenseCategory: string
{
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Internet = 'internet';
    case Groceries = 'groceries';
    case Household = 'household';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rent => __('Rent'),
            self::Utilities => __('Utilities'),
            self::Internet => __('Internet'),
            self::Groceries => __('Groceries'),
            self::Household => __('Household'),
            self::Other => __('Other'),
        };
    }

    /**
     * Heroicon name, shown next to the label (never instead of it).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Rent => 'home',
            self::Utilities => 'bolt',
            self::Internet => 'wifi',
            self::Groceries => 'shopping-cart',
            self::Household => 'sparkles',
            self::Other => 'tag',
        };
    }
}
