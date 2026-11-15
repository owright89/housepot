<?php

use App\Support\Money;

test('parses common formats', function (string $input, ?int $pence) {
    expect(Money::parse($input))->toBe($pence);
})->with([
    'whole pounds' => ['12', 1200],
    'one decimal' => ['12.5', 1250],
    'two decimals' => ['12.50', 1250],
    'pence only' => ['0.07', 7],
    'pound sign' => ['£96.40', 9640],
    'thousands' => ['£1,650.00', 165000],
    'spaces' => [' 1 650 ', 165000],
    'float trap' => ['0.29', 29],
    'zero' => ['0', 0],
]);

test('rejects anything that is not a plain amount', function (string $input) {
    expect(Money::parse($input))->toBeNull();
})->with([
    'empty' => [''],
    'letters' => ['abc'],
    'negative' => ['-5'],
    'three decimals' => ['1.234'],
    'bad grouping' => ['1,23'],
    'two dots' => ['1.2.3'],
    'leading dot' => ['.5'],
    'too big' => ['1234567890123'],
]);
