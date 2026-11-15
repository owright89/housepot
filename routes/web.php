<?php

use App\Http\Controllers\AcceptInviteController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('households', 'pages::households.index')->name('households.index');
    Route::livewire('households/{household}', 'pages::households.show')
        ->name('households.show')
        ->can('view', 'household');

    // Signed so links can't be forged or guessed. Expiry is checked by AcceptInvite, not the signature,
    // so an expired link shows a friendly message rather than a 403.
    Route::get('invites/{invite}', AcceptInviteController::class)
        ->name('invites.accept')
        ->middleware('signed');
});

require __DIR__.'/settings.php';
