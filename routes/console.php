<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process buffered Slack conversations every minute
Schedule::command('slack:process-conversations')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
