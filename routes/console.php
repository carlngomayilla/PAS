<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Queue\Console\MonitorCommand;
use Illuminate\Queue\Console\PruneFailedJobsCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Horizon\Console\SnapshotCommand;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alertes:notifier --refresh-metrics')
    ->dailyAt('07:30')
    ->onOneServer()
    ->withoutOverlapping(60);
Schedule::command('meetings:send-reminders')
    ->dailyAt('08:00')
    ->onOneServer()
    ->withoutOverlapping(60);
Schedule::command('anbg:planning-auto-archive --execute')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping(60);
Schedule::command('anbg:retention-run --execute')
    ->monthlyOn(1, '03:00')
    ->onOneServer()
    ->withoutOverlapping(360);

$usesRedisQueue = static fn (): bool => (string) config('queue.default') === 'redis';

if (class_exists(SnapshotCommand::class)) {
    Schedule::command('horizon:snapshot')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10)
        ->when($usesRedisQueue);
}

if (class_exists(MonitorCommand::class)) {
    Schedule::command('queue:monitor redis:notifications,redis:exports,redis:ai-imports,redis:default --max=100')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(5)
        ->when($usesRedisQueue);
}

if (class_exists(PruneFailedJobsCommand::class)) {
    Schedule::command('queue:prune-failed --hours=168')
        ->dailyAt('02:45')
        ->onOneServer()
        ->withoutOverlapping(60);
}
