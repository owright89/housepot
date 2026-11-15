<?php

use App\Models\HouseholdInvite;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete unused invite links that stopped working over a month ago (HouseholdInvite::prunable()).
Schedule::command('model:prune', ['--model' => [HouseholdInvite::class]])->daily();

// Horizon's dashboard graphs (throughput, wait times) are built from these snapshots.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
