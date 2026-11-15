<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    // Use the real Reverb (pusher-protocol) authoriser so the channel rules in routes/channels.php run.
    // Tests normally use the null broadcaster, so the channel rules are registered there;
    // register them again on the Reverb broadcaster.
    config(['broadcasting.default' => 'reverb']);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');
});

function authorise(User $user, Household $household)
{
    return actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-households.{$household->id}",
    ]);
}

test('a current member can listen to their household', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member)->create();

    authorise($member, $household)->assertOk()->assertJsonStructure(['auth']);
});

test('a former member cannot listen', function () {
    $former = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($former, leftAt: now())->create();

    authorise($former, $household)->assertForbidden();
});

test('a stranger cannot listen', function () {
    $household = Household::factory()->withOwner()->create();

    authorise(User::factory()->create(), $household)->assertForbidden();
});

test('guests cannot listen', function () {
    $household = Household::factory()->withOwner()->create();

    post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-households.{$household->id}"])
        ->assertForbidden();
});
