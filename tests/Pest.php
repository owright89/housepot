<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests boot the app (for helpers like __()) but never touch the database.
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Alice (owner), Bob and Cara share a flat. Ids ascend in that order.
 *
 * @return array{0: Household, 1: User, 2: User, 3: User}
 */
function flat(): array
{
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    $cara = User::factory()->create(['name' => 'Cara']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->withMember($cara)->create();

    return [$household, $alice, $bob, $cara];
}
