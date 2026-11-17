<?php

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('guests are redirected to the login page', function () {
    $household = Household::factory()->withOwner()->create();

    get(route('households.show', $household))->assertRedirect(route('login'));
});

test('current member sees the page', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember($user)
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertOk()
        ->assertSee($household->name);
});

test('former member is forbidden', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember($user, leftAt: now())
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertForbidden();
});

test('stranger is forbidden', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->withOwner()
        ->withMember()
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertForbidden();
});

test('only current members are listed', function () {
    $user = User::factory()->create(['name' => 'Dave']);
    $household = Household::factory()
        ->withOwner($user)
        ->withMember(User::factory()->create(['name' => 'Bob']))
        ->withMember(User::factory()->create(['name' => 'Carl']), leftAt: now())
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertSee('Dave')
        ->assertSee('Bob')
        ->assertDontSee('Carl');
});

test('an archived household shows the callout', function () {
    $user = User::factory()->create();
    $household = Household::factory()
        ->archived()
        ->withMember($user)
        ->create();

    actingAs($user)->get(route('households.show', $household))
        ->assertSee('This household is archived');
});

test('only the owner sees the invite buttons', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->withMember($member)->create();

    actingAs($owner)->get(route('households.show', [$household, 'members']))->assertSee('Invite housemate');
    actingAs($member)->get(route('households.show', [$household, 'members']))->assertDontSee('Invite housemate');
});

test('owner opening the invite modal gets a link', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    actingAs($owner);

    $component = Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call('openInvite')
        ->assertHasNoErrors();

    $invite = $household->invites()->sole();

    expect($invite->invited_by)->toBe($owner->id)
        ->and($component->get('inviteUrl'))->toBe($invite->url());
});

test('opening the invite modal again reuses the live link', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    actingAs($owner);

    $component = Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call('openInvite');
    $firstUrl = $component->get('inviteUrl');

    $component->call('openInvite');

    expect($household->invites()->count())->toBe(1)
        ->and($component->get('inviteUrl'))->toBe($firstUrl);
});

test('a new link replaces the old one, which stops working', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    actingAs($owner);

    $component = Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call('openInvite');
    $old = $household->invites()->sole();

    $component->call('newInviteLink');

    expect($old->refresh()->isUsable())->toBeFalse()
        ->and($household->invites()->usable()->count())->toBe(1)
        ->and($component->get('inviteUrl'))->toBe($household->activeInvite()->url());
});

test('a used link is not shown again; the next person gets a fresh one', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $used = HouseholdInvite::factory()->for($household)->accepted()->create();
    actingAs($owner);

    $component = Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call('openInvite');

    expect($component->get('inviteUrl'))->not->toBe($used->url())
        ->and($household->invites()->usable()->count())->toBe(1);
});

test('a member cannot open or replace an invite link', function (string $method) {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->withMember($member)->create();
    actingAs($member);

    Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call($method)
        ->assertForbidden();

    expect($household->invites()->count())->toBe(0);
})->with(['openInvite', 'newInviteLink']);

test('the owner of an archived household cannot create an invite', function () {
    $owner = User::factory()->create();
    $household = Household::factory()->archived()->withOwner($owner)->create();
    actingAs($owner);

    Livewire\Livewire::test('pages::households.show', ['household' => $household])
        ->call('openInvite')
        ->assertForbidden();

    expect($household->invites()->count())->toBe(0);
});

test('each section has its own URL, title and highlighted nav item', function (?string $section, string $title, string $navItem) {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner($user)->create(['name' => 'Flat 2']);

    $html = actingAs($user)->get(route('households.show', array_filter([$household, $section])))
        ->assertOk()
        ->assertSee("{$title} · Flat 2 - ")
        ->getContent();

    // Exactly one nav item is current (the attribute itself, not the `data-current:` styling classes)…
    expect(preg_match_all('/\sdata-current(?=[\s>=])/', $html))->toBe(1)
        // …and it's the right one.
        ->and(preg_match('/<a[^>]*data-current[^>]*data-test="'.$navItem.'"|<a[^>]*data-test="'.$navItem.'"[^>]*data-current/', $html))->toBe(1);
})->with([
    'bills' => [null, 'Bills', 'nav-bills'],
    'payments' => ['payments', 'Payments', 'nav-payments'],
    'members' => ['members', 'Members', 'nav-members'],
]);

test('an unknown section is a 404', function () {
    $user = User::factory()->create();
    $household = Household::factory()->withOwner($user)->create();

    actingAs($user)->get('/households/'.$household->id.'/nonsense')->assertNotFound();
});

test('the sidebar has no Households item once you have a household', function () {
    $user = User::factory()->create();
    Household::factory()->withOwner($user)->create();

    actingAs($user)->get(route('dashboard'))
        ->assertSeeHtml('data-test="nav-payments"')
        ->assertDontSeeHtml('href="'.route('households.index').'" data-flux-sidebar-item');
});
