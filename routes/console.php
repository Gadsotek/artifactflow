<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('artifactflow:dispatch-domain-events')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('artifactflow:prune-domain-events')
    ->dailyAt('03:10')
    ->withoutOverlapping();

Schedule::command('artifactflow:prune-credentials')
    ->dailyAt('03:20')
    ->withoutOverlapping();
