<?php

use App\Enums\SettlementStatus;
use App\Models\Expense;
use App\Models\ExpenseSplit;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Database\QueryException;

test('expense factory creates a valid expense in pence', function () {
    $expense = Expense::factory()->create();
    $expense->refresh();

    expect($expense->amount)->toBeInt()->toBeGreaterThan(0);
    expect($expense->spent_on->format('H:i:s'))->toBe('00:00:00');
    expect($expense->version)->toBe(1);
});

test('expense split belongs to a parent expense and cascades on force delete', function () {
    $expenseSplit = ExpenseSplit::factory()->create();
    $expenseSplit->refresh();

    expect($expenseSplit->expense)->toBeInstanceOf(Expense::class);

    $expenseSplit->expense->forceDelete();

    expect(ExpenseSplit::find($expenseSplit->id))->toBeNull();
});

test('expense factory assigns the given user as payer via paid_by', function () {
    $payer = User::factory()->create();
    $expense = Expense::factory()->for($payer, 'payer')->create();
    $expense->refresh();

    expect($expense->payer->id)->toBe($payer->id);
    expect($expense->paid_by)->toBe($payer->id);
});

test('settlement factory state sets status and confirmed_at', function (string $state, SettlementStatus $expected, bool $hasConfirmedAt) {
    $settlement = Settlement::factory()->{$state}()->create();
    $settlement->refresh();

    expect($settlement->status)->toBe($expected)
        ->and($settlement->confirmed_at !== null)->toBe($hasConfirmedAt);
})
    ->with([
        'pending' => ['pending', SettlementStatus::Pending, false],
        'confirmed' => ['confirmed', SettlementStatus::Confirmed, true],
        'rejected' => ['rejected', SettlementStatus::Rejected, false],
    ]);

test('settlement factory creates correct from and to users', function () {
    $fromUser = User::factory()->create();
    $toUser = User::factory()->create();

    $settlement = Settlement::factory()->for($fromUser, 'fromUser')->for($toUser, 'toUser')->create();
    $settlement->refresh();

    expect($settlement->from_user_id)->toBe($fromUser->id)
        ->and($settlement->to_user_id)->toBe($toUser->id);
});

test('database rejects invalid rows via CHECK constraint', function (Closure $insert, string $constraint) {
    expect($insert)->toThrow(QueryException::class, $constraint);
})->with([
    'zero expense amount' => [
        fn () => Expense::factory()->create(['amount' => 0]),
        'expenses_amount_positive',
    ],
    'negative expense amount' => [
        fn () => Expense::factory()->create(['amount' => -100]),
        'expenses_amount_positive',
    ],
    'zero settlement amount' => [
        fn () => Settlement::factory()->create(['amount' => 0]),
        'settlements_amount_positive',
    ],
    'negative settlement amount' => [
        fn () => Settlement::factory()->create(['amount' => -100]),
        'settlements_amount_positive',
    ],
    'settlement to self' => [
        function () {
            $user = User::factory()->create();

            Settlement::factory()
                ->for($user, 'fromUser')
                ->for($user, 'toUser')
                ->create();
        },
        'settlements_distinct_users',
    ],
]);
