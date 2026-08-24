<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('academic:sync-calendar')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/academic-calendar.log'));

Schedule::command('clinic:cancel-no-shows')
    ->dailyAt('23:30')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/clinic-no-shows.log'));
