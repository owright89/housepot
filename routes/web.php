<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('households', 'pages::households.index')->name('households.index');
    Route::livewire('households/{household}', 'pages::households.show')
        ->name('households.show')
        ->can('view', 'household');
});

require __DIR__.'/settings.php';
