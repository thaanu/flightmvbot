<?php

use Illuminate\Support\Facades\Schedule;

// Poll fis.com.mv once a minute; the bot only ever reads local storage.
Schedule::command('fis:sync')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->when(fn () => config('fis.sync_enabled'));

Schedule::command('fis:prune')->dailyAt('03:30');

Schedule::command('fis:weather')
    ->everyTenMinutes()
    ->when(fn () => config('fis.sync_enabled'));
