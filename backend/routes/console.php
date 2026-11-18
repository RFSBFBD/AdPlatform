<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Incremental sync runs every 3 hours across active accounts
Schedule::command('adplatform:sync-all --type=incremental')
    ->everyThreeHours()
    ->withoutOverlapping(60)
    ->runInBackground();
