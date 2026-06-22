<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

app(Schedule::class)->call(function (): void {
    Artisan::call('peerscope:collect');
})
    ->name('peerscope.collect')
    ->everyMinute()
    ->withoutOverlapping(60);

app(Schedule::class)->call(function (): void {
    Artisan::call('peerscope:import-users-folder');
})
    ->name('peerscope.import-users-folder')
    ->everyMinute()
    ->withoutOverlapping(10);
