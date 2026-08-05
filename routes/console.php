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

Schedule::command('artifactflow:prune-external-shares')
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command('artifactflow:prune-rate-limit-cache')
    ->dailyAt('03:40')
    ->withoutOverlapping();
