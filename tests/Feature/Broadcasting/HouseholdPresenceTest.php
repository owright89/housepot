<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    // Use the real Reverb (pusher-protocol) authoriser so the channel rules in routes/channels.php run.
    config(['broadcasting.default' => 'reverb']);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');
});

function joinPresence(User $user, Household $household)
{
    return actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "presence-household.{$household->id}",
    ]);
}

test('a current member joins presence and shares only their id and first name', function () {
    $member = User::factory()->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);
    $household = Household::factory()->withOwner()->withMember($member)->create();

    $response = joinPresence($member, $household)->assertOk();

    expect(json_decode($response->json('channel_data'), true))->toBe([
        'user_id' => (string) $member->id, // the pusher protocol sends it as a string; the UI uses user_info.id
        'user_info' => ['id' => $member->id, 'name' => 'Bob'],
    ]);
});

test('former members and strangers cannot join presence', function () {
    $former = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($former, leftAt: now())->create();

    joinPresence($former, $household)->assertForbidden();
    joinPresence(User::factory()->create(), $household)->assertForbidden();
});

test('the household page lists the other members for the online avatars', function () {
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        ->assertSeeHtml('data-test="household-presence"')
        ->assertSeeHtml("channel: 'household.{$household->id}'")
        ->assertSeeHtml("online.includes({$bob->id})")
        ->assertDontSeeHtml("online.includes({$alice->id})");
});

test('the bill form announces typing so others see "X is adding a bill…"', function () {
    $alice = User::factory()->create(['name' => 'Alice Smith']);
    $bob = User::factory()->create(['name' => 'Bob Jones']);
    $household = Household::factory()->withOwner($alice)->withMember($bob)->create();
    actingAs($alice);

    Livewire::test('pages::households.show', ['household' => $household])
        // The bill form raises the events…
        ->assertSeeHtml("\$dispatch('household-typing'")
        ->assertSeeHtml("\$dispatch('household-typing-stopped')")
        // …and the presence component whispers them and shows other people's.
        ->assertSeeHtml("x-on:household-typing.window=\"presence?.whisper('typing'")
        ->assertSeeHtml(".listenForWhisper('typing'")
        ->assertSeeHtml('data-test="typing-indicator"');
});
