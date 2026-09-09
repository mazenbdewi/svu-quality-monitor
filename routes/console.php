<?php

use App\Services\SystemHealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('services:check-due')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(fn (): mixed => app(SystemHealthService::class)->recordSchedulerHeartbeat())
    ->name('system-health:scheduler-heartbeat')
    ->everyMinute()
    ->evenInMaintenanceMode()
    ->withoutOverlapping();

Schedule::command('system:check-background-health')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('reliability:calculate --period=daily')
    ->dailyAt('00:10')
    ->withoutOverlapping();

Schedule::command('sla:calculate')
    ->dailyAt('00:20')
    ->withoutOverlapping();

Schedule::command('control-charts:calculate --period=daily')
    ->dailyAt('00:25')
    ->withoutOverlapping();

Schedule::command('backup:create --scheduled')
    ->dailyAt('02:00')
    ->withoutOverlapping(120)
    ->onFailure(fn () => Log::error('Scheduled backup command failed.'));

Schedule::command('backup:cleanup --scheduled')
    ->dailyAt('03:00')
    ->withoutOverlapping(120);
