<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

app(Schedule::class)->command('peerscope:collect')
    ->everyMinute()
    ->withoutOverlapping(60);

app(Schedule::class)->command('peerscope:import-users-folder')
    ->everyMinute()
    ->withoutOverlapping(10);
