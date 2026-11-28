<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('postar:publish-scheduled')->everyMinute();
Schedule::command('postar:check-processing-posts')->everyFiveMinutes();
Schedule::command('postar:poll-analytics')->everySixHours();
Schedule::command(\App\Console\Commands\RefreshTikTokTokens::class)->hourly();
Schedule::command(\App\Console\Commands\RefreshInstagramTokens::class)->daily();

// Security: Purge expired password reset tokens
Schedule::command('auth:purge-expired-reset-tokens')->daily();

// Sync canceled subscriptions daily
Schedule::command('subscriptions:sync-canceled')->daily();

// Security: Cleanup expired presigned URL uploads
Schedule::command('uploads:cleanup-expired')->dailyAt('02:00');

// Security: Daily database backup at 2 AM (compressed)
Schedule::command('backup:database --compress')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::alert('Database backup failed!');
    });

// Security: Remove backups older than 7 days at 3 AM
Schedule::command('backups:cleanup --keep=7')
    ->dailyAt('03:00')
    ->withoutOverlapping();
