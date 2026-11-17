<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable 2FA');
});

test('without a recent confirmation the page asks for the password inline', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Confirm it&#039;s you', escape: false)
        ->assertSee('data-test="security-locked"', escape: false)
        ->assertDontSee('Enable 2FA')
        ->assertDontSee('Update password');

    // A passkey confirmation redirects to the intended URL: back to this page.
    expect(session('url.intended'))->toBe(route('security.edit'));
});

test('confirming the password inline unlocks the page', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->assertSet('unlocked', false)
        ->set('confirmablePassword', 'password')
        ->call('confirmPassword')
        ->assertHasNoErrors()
        ->assertSet('unlocked', true)
        ->assertSee('Update password');

    // The same session key as Laravel's password.confirm, so Fortify's protected endpoints accept it.
    expect(session('auth.password_confirmed_at'))->toBeInt();
});

test('a wrong password keeps the page locked', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('confirmablePassword', 'wrong-password')
        ->call('confirmPassword')
        ->assertHasErrors('confirmablePassword')
        ->assertSet('unlocked', false);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('confirming is rate limited after five wrong passwords', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->actingAs($user);
    $page = Livewire::test('pages::settings.security');

    foreach (range(1, 5) as $attempt) {
        $page->set('confirmablePassword', 'wrong')->call('confirmPassword');
    }

    // Even the right password is refused while throttled.
    $page->set('confirmablePassword', 'password')
        ->call('confirmPassword')
        ->assertHasErrors('confirmablePassword')
        ->assertSet('unlocked', false);
});

test('actions do nothing once the confirmation has expired', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($user);
    session(['auth.password_confirmed_at' => time()]);

    $page = Livewire::test('pages::settings.security')->assertSet('unlocked', true);

    session(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1]);

    $page->call('disable')->assertSet('unlocked', false);

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('security settings page renders without two factor when feature is disabled', function () {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function () {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security');

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    session(['auth.password_confirmed_at' => time()]);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);
    session(['auth.password_confirmed_at' => time()]);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
